<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling;

use Ahmed29345\LlmToolCalling\Chat\ChatProvider;
use Ahmed29345\LlmToolCalling\Chat\Message;
use Ahmed29345\LlmToolCalling\Chat\Role;
use Ahmed29345\LlmToolCalling\Chat\ToolCall;
use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use Ahmed29345\LlmToolCalling\Exception\ToolException;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Runs the tool-calling loop for one actor.
 *
 * Each step sends the conversation and the actor's allowed tools to the model.
 * If the model asks for tools, each call is checked and run, its result is
 * appended, and the loop continues; a reply without tool calls ends the run.
 *
 * The model's output is treated as untrusted input. Every requested call is
 * authorised again at execution time, and a bad call (unknown tool, forbidden
 * tool, malformed arguments) becomes an error message the model can read and
 * recover from instead of an exception that ends the conversation.
 */
final class Agent
{
    public function __construct(
        private readonly ChatProvider $provider,
        private readonly ToolRegistry $tools,
        private readonly ?string $systemPrompt = null,
        private readonly int $maxSteps = 8,
    ) {
        if ($maxSteps < 1) {
            throw new InvalidArgumentException('maxSteps must be at least 1.');
        }
    }

    /**
     * @throws ProviderException
     */
    public function ask(Actor $actor, string $prompt): AgentResult
    {
        return $this->run($actor, [Message::user($prompt)]);
    }

    /**
     * Continue a conversation, e.g. with AgentResult::$messages plus a new user message.
     *
     * @param list<Message> $messages
     *
     * @throws ProviderException
     */
    public function run(Actor $actor, array $messages): AgentResult
    {
        if ($this->systemPrompt !== null && ($messages[0] ?? null)?->role !== Role::System) {
            array_unshift($messages, Message::system($this->systemPrompt));
        }

        $offered = $this->tools->forActor($actor);
        $invocations = [];

        for ($step = 1; $step <= $this->maxSteps; $step++) {
            $reply = $this->provider->chat($messages, $offered)->message;
            $messages[] = $reply;

            if (!$reply->hasToolCalls()) {
                return new AgentResult($reply->content ?? '', StopReason::Completed, $messages, $invocations, $step);
            }

            foreach ($reply->toolCalls as $call) {
                $invocation = $this->invoke($call, $actor);
                $invocations[] = $invocation;
                $messages[] = Message::tool($call->id, $call->name, $invocation->output);
            }
        }

        return new AgentResult(null, StopReason::MaxStepsReached, $messages, $invocations, $this->maxSteps);
    }

    private function invoke(ToolCall $call, Actor $actor): ToolInvocation
    {
        $tool = $this->tools->get($call->name);

        // Unknown and forbidden calls get the same answer, so the model cannot
        // probe for privileged tools. The invocation record keeps the real reason.
        if ($tool === null || !$this->tools->allows($actor, $tool)) {
            return new ToolInvocation(
                $call,
                $tool === null ? ToolOutcome::UnknownTool : ToolOutcome::Forbidden,
                self::error('tool_not_available', sprintf('No tool named "%s" is available to you.', $call->name)),
            );
        }

        try {
            $arguments = $call->decodedArguments();
        } catch (JsonException|InvalidArgumentException $e) {
            return new ToolInvocation(
                $call,
                ToolOutcome::InvalidArguments,
                self::error('invalid_arguments', 'Arguments must be a valid JSON object (' . $e->getMessage() . ').'),
                $e,
            );
        }

        $missing = array_values(array_diff(self::requiredArguments($tool), array_keys($arguments)));

        if ($missing !== []) {
            return new ToolInvocation(
                $call,
                ToolOutcome::InvalidArguments,
                self::error('invalid_arguments', 'Missing required argument(s): ' . implode(', ', $missing) . '.'),
            );
        }

        try {
            $output = self::encodeOutput($tool->handle($arguments, $actor));
        } catch (ToolException $e) {
            return new ToolInvocation($call, ToolOutcome::Failed, self::error('tool_error', $e->getMessage()), $e);
        } catch (Throwable $e) {
            // Internal details (SQL, stack traces, paths) must not reach the model or the user.
            return new ToolInvocation($call, ToolOutcome::Failed, self::error('tool_failed', 'The tool failed unexpectedly.'), $e);
        }

        return new ToolInvocation($call, ToolOutcome::Succeeded, $output);
    }

    /**
     * @return list<string>
     */
    private static function requiredArguments(Tool $tool): array
    {
        $required = $tool->parameters()['required'] ?? [];

        return is_array($required) ? array_values(array_filter($required, 'is_string')) : [];
    }

    /**
     * @throws JsonException
     */
    private static function encodeOutput(mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function error(string $code, string $message): string
    {
        return json_encode(
            ['error' => ['code' => $code, 'message' => $message]],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ) ?: '{"error":{"code":"' . $code . '"}}';
    }
}
