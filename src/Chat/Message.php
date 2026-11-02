<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Chat;

/**
 * One provider-neutral chat message. Providers translate it to their own wire format.
 */
final readonly class Message
{
    /**
     * @param list<ToolCall> $toolCalls
     */
    private function __construct(
        public Role $role,
        public ?string $content,
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?string $toolName = null,
    ) {
    }

    public static function system(string $content): self
    {
        return new self(Role::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(Role::User, $content);
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    public static function assistant(?string $content, array $toolCalls = []): self
    {
        return new self(Role::Assistant, $content, $toolCalls);
    }

    /**
     * The result of a tool call, answering the call with the given id.
     */
    public static function tool(string $toolCallId, string $toolName, string $content): self
    {
        return new self(Role::Tool, $content, [], $toolCallId, $toolName);
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
