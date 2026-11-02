<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling;

use Ahmed29345\LlmToolCalling\Chat\ToolCall;
use Throwable;

/**
 * Audit record of one tool call the model asked for: what it asked, what happened,
 * and what was sent back. Rejected calls are recorded too.
 */
final readonly class ToolInvocation
{
    public function __construct(
        public ToolCall $call,
        public ToolOutcome $outcome,
        public string $output,
        public ?Throwable $exception = null,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->outcome === ToolOutcome::Succeeded;
    }
}
