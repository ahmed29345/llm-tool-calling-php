<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Chat;

use InvalidArgumentException;

/**
 * The model's reply to one chat request.
 */
final readonly class ChatResponse
{
    /**
     * @param array<string, mixed> $raw The decoded provider payload, kept for logging and debugging.
     */
    public function __construct(
        public Message $message,
        public ?string $finishReason = null,
        public array $raw = [],
    ) {
        if ($message->role !== Role::Assistant) {
            throw new InvalidArgumentException('A chat response must carry an assistant message.');
        }
    }

    public static function text(string $content): self
    {
        return new self(Message::assistant($content), 'stop');
    }

    public static function toolCalls(ToolCall ...$calls): self
    {
        return new self(Message::assistant(null, array_values($calls)), 'tool_calls');
    }
}
