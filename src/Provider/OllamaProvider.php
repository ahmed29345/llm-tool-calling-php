<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Provider;

use Ahmed29345\LlmToolCalling\Chat\ChatProvider;
use Ahmed29345\LlmToolCalling\Chat\ChatResponse;
use Ahmed29345\LlmToolCalling\Chat\Message;
use Ahmed29345\LlmToolCalling\Chat\Role;
use Ahmed29345\LlmToolCalling\Chat\ToolCall;
use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use Ahmed29345\LlmToolCalling\Http\JsonHttpClient;
use Ahmed29345\LlmToolCalling\Tool;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use stdClass;

/**
 * Ollama's native chat endpoint (POST {baseUrl}/api/chat) with tool support, non-streaming.
 *
 * Differences from OpenAI that this class hides: tool call arguments are JSON
 * objects rather than strings, tool calls may have no id, and tool results are
 * matched by tool name.
 */
final class OllamaProvider implements ChatProvider
{
    public const DEFAULT_BASE_URL = 'http://localhost:11434';

    private readonly JsonHttpClient $http;

    private readonly string $endpoint;

    private int $generatedIds = 0;

    /**
     * @param array<string, mixed>  $options Model options sent as "options", e.g. ['temperature' => 0, 'num_ctx' => 8192]
     * @param array<string, string> $headers Extra HTTP headers, e.g. for an authenticating proxy
     */
    public function __construct(
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        private readonly string $model,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly array $options = [],
        private readonly array $headers = [],
    ) {
        $this->http = new JsonHttpClient($client, $requestFactory, $streamFactory);
        $this->endpoint = rtrim($baseUrl, '/') . '/api/chat';
    }

    public function chat(array $messages, array $tools): ChatResponse
    {
        $payload = [
            'model' => $this->model,
            'messages' => array_map($this->encodeMessage(...), $messages),
            'stream' => false,
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(
                static fn (Tool $tool): array => FunctionDefinition::fromTool($tool),
                $tools,
            );
        }

        if ($this->options !== []) {
            $payload['options'] = $this->options;
        }

        return $this->decodeResponse($this->http->post($this->endpoint, $payload, $this->headers));
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeMessage(Message $message): array
    {
        $encoded = ['role' => $message->role->value, 'content' => $message->content ?? ''];

        if ($message->role === Role::Assistant && $message->hasToolCalls()) {
            $encoded['tool_calls'] = array_map(
                static fn (ToolCall $call): array => [
                    'function' => ['name' => $call->name, 'arguments' => self::argumentsObject($call)],
                ],
                $message->toolCalls,
            );
        }

        if ($message->role === Role::Tool) {
            $encoded['tool_name'] = $message->toolName;
        }

        return $encoded;
    }

    /**
     * Ollama expects arguments as a JSON object. A call the model sent with
     * broken arguments is replayed with an empty object; the tool result that
     * follows it already tells the model what went wrong.
     *
     * @return array<string, mixed>|stdClass
     */
    private static function argumentsObject(ToolCall $call): array|stdClass
    {
        try {
            $arguments = $call->decodedArguments();
        } catch (JsonException|InvalidArgumentException) {
            return new stdClass();
        }

        return $arguments === [] ? new stdClass() : $arguments;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ProviderException
     */
    private function decodeResponse(array $data): ChatResponse
    {
        $message = $data['message'] ?? null;

        if (!is_array($message)) {
            throw ProviderException::unexpectedResponse('missing "message"');
        }

        $toolCalls = [];

        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $index => $call) {
            $function = is_array($call) ? ($call['function'] ?? null) : null;

            if (!is_array($function) || !is_string($function['name'] ?? null)) {
                throw ProviderException::unexpectedResponse(sprintf('tool call #%s has no function name', $index));
            }

            $id = is_string($call['id'] ?? null) && $call['id'] !== ''
                ? $call['id']
                : sprintf('ollama_call_%d', ++$this->generatedIds);

            $toolCalls[] = new ToolCall($id, $function['name'], ToolCall::argumentsToJson($function['arguments'] ?? null));
        }

        $content = is_string($message['content'] ?? null) && $message['content'] !== '' ? $message['content'] : null;

        return new ChatResponse(
            Message::assistant($content, $toolCalls),
            is_string($data['done_reason'] ?? null) ? $data['done_reason'] : null,
            $data,
        );
    }
}
