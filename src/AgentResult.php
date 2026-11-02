<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling;

use Ahmed29345\LlmToolCalling\Chat\Message;

final readonly class AgentResult
{
    /**
     * @param ?string              $answer      The final reply, or null when the step limit was hit first
     * @param list<Message>        $messages    The full conversation, ready to pass back to Agent::run() for a follow-up turn
     * @param list<ToolInvocation> $invocations Every tool call requested in this run, including rejected ones
     * @param int                  $steps       Number of model requests made
     */
    public function __construct(
        public ?string $answer,
        public StopReason $stopReason,
        public array $messages,
        public array $invocations,
        public int $steps,
    ) {
    }

    public function completed(): bool
    {
        return $this->stopReason === StopReason::Completed;
    }

    /**
     * @return list<ToolInvocation>
     */
    public function rejectedInvocations(): array
    {
        return array_values(array_filter(
            $this->invocations,
            static fn (ToolInvocation $invocation): bool => in_array(
                $invocation->outcome,
                [ToolOutcome::UnknownTool, ToolOutcome::Forbidden, ToolOutcome::InvalidArguments],
                true,
            ),
        ));
    }
}
