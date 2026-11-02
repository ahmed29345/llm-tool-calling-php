<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Provider;

use Ahmed29345\LlmToolCalling\Chat\ChatProvider;
use Ahmed29345\LlmToolCalling\Chat\ChatResponse;
use Ahmed29345\LlmToolCalling\Chat\Message;
use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use Ahmed29345\LlmToolCalling\Tool;
use Closure;

/**
 * Replays scripted responses in order and records every request, for tests and demos.
 *
 * A script entry is either a ChatResponse or a closure that receives the
 * request (messages and tools) and returns one, for replies that depend on it.
 *
 * @phpstan-type Script ChatResponse|Closure(list<Message>, list<Tool>): ChatResponse
 * @phpstan-type Request array{messages: list<Message>, tools: list<Tool>}
 */
final class FakeProvider implements ChatProvider
{
    /** @var list<Script> */
    private array $script;

    /** @var list<Request> */
    private array $requests = [];

    /**
     * @param Script ...$responses
     */
    public function __construct(ChatResponse|Closure ...$responses)
    {
        $this->script = array_values($responses);
    }

    /**
     * @param Script $response
     */
    public function push(ChatResponse|Closure $response): self
    {
        $this->script[] = $response;

        return $this;
    }

    public function chat(array $messages, array $tools): ChatResponse
    {
        $this->requests[] = ['messages' => $messages, 'tools' => $tools];

        $next = array_shift($this->script);

        if ($next === null) {
            throw new ProviderException(sprintf(
                'FakeProvider ran out of scripted responses at request #%d.',
                count($this->requests),
            ));
        }

        return $next instanceof ChatResponse ? $next : $next($messages, $tools);
    }

    /**
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * Names of the tools offered to the model in the given request (0-based).
     *
     * @return list<string>
     */
    public function toolNamesOffered(int $request = 0): array
    {
        return array_map(
            static fn (Tool $tool): string => $tool->name(),
            $this->requests[$request]['tools'] ?? [],
        );
    }

    public function remaining(): int
    {
        return count($this->script);
    }
}
