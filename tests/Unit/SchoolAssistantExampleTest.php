<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Tests\Unit;

use Ahmed29345\LlmToolCalling\Actor;
use Ahmed29345\LlmToolCalling\Agent;
use Ahmed29345\LlmToolCalling\Chat\ChatResponse;
use Ahmed29345\LlmToolCalling\Chat\ToolCall;
use Ahmed29345\LlmToolCalling\Examples\School\AttendanceSummaryTool;
use Ahmed29345\LlmToolCalling\Examples\School\ClassRosterTool;
use Ahmed29345\LlmToolCalling\Examples\School\UnpaidInvoicesTool;
use Ahmed29345\LlmToolCalling\Provider\FakeProvider;
use Ahmed29345\LlmToolCalling\ToolOutcome;
use Ahmed29345\LlmToolCalling\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end run of the example tools through the agent, with a scripted model.
 */
final class SchoolAssistantExampleTest extends TestCase
{
    public function test_finance_data_reaches_the_model_only_for_actors_with_finance_access(): void
    {
        $registry = new ToolRegistry([new ClassRosterTool(), new AttendanceSummaryTool(), new UnpaidInvoicesTool()]);
        $teacher = new Actor('teacher-7', ['teacher'], ['attendance.view']);
        $principal = new Actor('principal-1', ['principal'], ['attendance.*', 'finance.*']);

        $outputs = [];

        foreach ([$teacher, $principal] as $actor) {
            $provider = new FakeProvider(
                ChatResponse::toolCalls(ToolCall::withArguments('c1', 'unpaid_invoices', ['class' => 'Grade 3A'])),
                ChatResponse::text('done'),
            );

            $result = (new Agent($provider, $registry))->ask($actor, 'Who owes fees in Grade 3A?');
            $outputs[$actor->id] = $result->invocations[0];
        }

        self::assertSame(ToolOutcome::Forbidden, $outputs['teacher-7']->outcome);
        self::assertStringNotContainsString('INV-', $outputs['teacher-7']->output);

        self::assertSame(ToolOutcome::Succeeded, $outputs['principal-1']->outcome);
        self::assertStringContainsString('INV-1002', $outputs['principal-1']->output);
        self::assertStringNotContainsString('INV-1003', $outputs['principal-1']->output, 'Filtered to Grade 3A.');
    }

    public function test_roster_rejects_unknown_classes_with_a_readable_error(): void
    {
        $provider = new FakeProvider(
            ChatResponse::toolCalls(ToolCall::withArguments('c1', 'class_roster', ['class' => 'Grade 9Z'])),
            ChatResponse::text('done'),
        );

        $result = (new Agent($provider, new ToolRegistry([new ClassRosterTool()])))->ask(new Actor(1), 'x');

        self::assertSame(ToolOutcome::Failed, $result->invocations[0]->outcome);
        self::assertStringContainsString('Unknown class', $result->invocations[0]->output);
    }
}
