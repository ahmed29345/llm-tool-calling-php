<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling;

use Ahmed29345\LlmToolCalling\Exception\ToolException;

/**
 * A capability the model may ask to run.
 */
interface Tool
{
    /**
     * Unique name the model uses to call the tool. Letters, digits, "_" and "-", at most 64 characters.
     */
    public function name(): string;

    /**
     * Plain-language explanation of when the model should use the tool.
     */
    public function description(): string;

    /**
     * JSON Schema describing the arguments object, e.g.
     * ['type' => 'object', 'properties' => [...], 'required' => [...]].
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Permission the actor needs, or null when every actor may use the tool.
     */
    public function requiredPermission(): ?string;

    /**
     * Run the tool. The return value is sent back to the model: strings as-is,
     * anything else JSON-encoded.
     *
     * Throw ToolException for an error the model should see and can act on
     * (for example "student not found"). Any other exception is reported to the
     * model as a generic failure and kept on the ToolInvocation for logging.
     *
     * @param array<string, mixed> $arguments Decoded arguments chosen by the model; validate them.
     *
     * @throws ToolException
     */
    public function handle(array $arguments, Actor $actor): mixed;
}
