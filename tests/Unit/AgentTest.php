<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Tests\Unit;

use Ahmed29345\LlmToolCalling\Actor;
use Ahmed29345\LlmToolCalling\Agent;
use Ahmed29345\LlmToolCalling\CallableTool;
use Ahmed29345\LlmToolCalling\Chat\ChatResponse;
use Ahmed29345\LlmToolCalling\Chat\Message;
use Ahmed29345\LlmToolCalling\Chat\Role;
use Ahmed29345\LlmToolCalling\Chat\ToolCall;
use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use Ahmed29345\LlmToolCalling\Exception\ToolException;
use Ahmed29345\LlmToolCalling\Provider\FakeProvider;
use Ahmed29345\LlmToolCalling\StopReason;
use Ahmed29345\LlmToolCalling\Tool;
use Ahmed29345\LlmToolCalling\ToolInvocation;
use Ahmed29345\LlmToolCalling\ToolOutcome;
use Ahmed29345\LlmToolCalling\ToolRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentTest extends TestCase
{
    /** @var array<string, list<array<string, mixed>>> Arguments each tool was actually run with */
    private array $calls = [];

    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->registry = new ToolRegistry([
            $this->tool('attendance_summary', 'attendance.view', ['class'], static fn (array $args): array => [
                'class' => $args['class'],
                'absent' => 2,
            ]),
            $this->tool('unpaid_invoices', 'finance.view', [], static fn (): array => ['count' => 1]),
            $this->tool('echo', null, [], static fn (array $args): string => 'echo:' . json_encode($args)),
        ]);
    }

    public function test_returns_the_answer_when_no_tools_are_requested(): void
    {
        $provider = new FakeProvider(ChatResponse::text('Hello.'));

        $result = (new Agent($provider, $this->registry))->ask($this->teacher(), 'Hi');

        self::assertTrue($result->completed());
        self::assertSame('Hello.', $result->answer);
        self::assertSame(1, $result->steps);
        self::assertSame([], $result->invocations);
    }

    public function test_only_the_actors_tools_are_offered_to_the_model(): void
    {
        $provider = new FakeProvider(ChatResponse::text('ok'), ChatResponse::text('ok'));
        $agent = new Agent($provider, $this->registry);

        $agent->ask($this->teacher(), 'Hi');
        $agent->ask(new Actor('a', ['accountant'], ['finance.view']), 'Hi');

        self::assertSame(['attendance_summary', 'echo'], $provider->toolNamesOffered(0));
        self::assertSame(['unpaid_invoices', 'echo'], $provider->toolNamesOffered(1));
    }

    public function test_runs_tools_across_several_steps_and_feeds_results_back(): void
    {
        $provider = new FakeProvider(
            ChatResponse::toolCalls(ToolCall::withArguments('c1', 'attendance_summary', ['class' => '3A'])),
            ChatResponse::toolCalls(
                ToolCall::withArguments('c2', 'echo', ['n' => 1]),
                ToolCall::withArguments('c3', 'echo', ['n' => 2]),
            ),
            ChatResponse::text('Two absences in 3A.'),
        );

        $result = (new Agent($provider, $this->registry, 'Be brief.'))->ask($this->teacher(), 'Absences in 3A?');

        self::assertSame('Two absences in 3A.', $result->answer);
        self::assertSame(3, $result->steps);
        self::assertSame([['class' => '3A']], $this->calls['attendance_summary']);
        self::assertCount(2, $this->calls['echo']);
        self::assertSame(
            [ToolOutcome::Succeeded, ToolOutcome::Succeeded, ToolOutcome::Succeeded],
            array_map(static fn (ToolInvocation $i): ToolOutcome => $i->outcome, $result->invocations),
        );

        // system, user, assistant(c1), tool, assistant(c2,c3), tool, tool, assistant(final)
        self::assertSame(
            ['system', 'user', 'assistant', 'tool', 'assistant', 'tool', 'tool', 'assistant'],
            array_map(static fn (Message $m): string => $m->role->value, $result->messages),
        );

        $toolMessage = $result->messages[3];
        self::assertSame('c1', $toolMessage->toolCallId);
        self::assertSame('attendance_summary', $toolMessage->toolName);
        self::assertSame('{"class":"3A","absent":2}', $toolMessage->content);

        // The third request carried both echo results.
        $thirdRequest = $provider->requests()[2]['messages'];
        self::assertSame('echo:{"n":2}', $thirdRequest[6]->content);
    }

    public function test_permission_is_checked_again_when_the_model_calls_a_tool_it_was_not_offered(): void
    {
        $provider = new FakeProvider(
            ChatResponse::toolCalls(new ToolCall('c1', 'unpaid_invoices', '{}')),
            ChatResponse::text('I cannot access invoices.'),
        );

        $result = (new Agent($provider, $this->registry))->ask($this->teacher(), 'Who owes fees?');

        self::assertArrayNotHasKey('unpaid_invoices', $this->calls, 'Forbidden tool must not run.');
        self::assertSame(ToolOutcome::Forbidden, $result->invocations[0]->outcome);
        self::assertSame($result->invocations, $result->rejectedInvocations());
        self::assertErrorCode('tool_not_available', $result->invocations[0]->output);
        self::assertSame('I cannot access invoices.', $result->answer);
    }

    public function test_unknown_and_forbidden_tools_look_the_same_to_the_model(): void
    {
        $provider = new FakeProvider(
            ChatResponse::toolCalls(
                new ToolCall('c1', 'unpaid_invoices', '{}'),
                new ToolCall('c2', 'delete_everything', '{}'),
            ),
            ChatResponse::text('done'),
        );

        $result = (new Agent($provider, $this->registry))->ask($this->teacher(), 'x');

        [$forbidden, $unknown] = $result->invocations;
        self::assertSame(ToolOutcome::Forbidden, $forbidden->outcome);
        self::assertSame(ToolOutcome::UnknownTool, $unknown->outcome);
        self::assertSame(
            str_replace('unpaid_invoices', 'X', $forbidden->output),
            str_replace('delete_everything', 'X', $unknown->output),
        );
    }

    public function test_invalid_json_arguments_are_reported_to_the_model(): void
    {
        $provider = new FakeProvider(
            ChatResponse::toolCalls(new ToolCall('c1', 'attendance_summary', '{"class": "3A"')),
            ChatResponse::toolCalls(new ToolCall('c2', 'attendance_summary', '"3A"')),
            ChatResponse::text('Sorry.'),
        );

        $result = (new Agent($provider, $this->registry))->ask($this->teacher(), 'x');

        self::assertArrayNotHasKey('attendance_summary', $this->calls);
        self::assertSame(ToolOutcome::InvalidArguments, $result->invocations[0]->outcome);
        self::assertSame(ToolOutcome::InvalidArguments, $result->invocations[1]->outcome);
        self::assertErrorCode('invalid_arguments', $result->invocations[0]->output);
        self::assertTrue($result->completed());
    }

    public function test_missing_required_arguments_are_reported_to_the_model(): void
    {
        $provider = new FakeProvider(
            ChatResponse::toolCalls(new ToolCall('c1', 'attendance_summary', '{}')),
            ChatResponse::text('Which class?'),
        );

        $result = (new Agent($provider, $this->registry))->ask($this->teacher(), 'x');

        self::assertArrayNotHasKey('attendance_summary', $this->calls);
        self::assertSame(ToolOutcome::InvalidArguments, $result->invocations[0]->outcome);
        self::assertStringContainsString('Missing required argument(s): class.', $result->invocations[0]->output);
    }

    public function test_stops_at_the_step_limit(): void
    {
        $loop = static fn (): ChatResponse => ChatResponse::toolCalls(new ToolCall('c', 'echo', '{}'));
        $provider = new FakeProvider($loop, $loop, $loop, $loop);

        $result = (new Agent($provider, $this->registry, maxSteps: 3))->ask($this->teacher(), 'x');

        self::assertSame(StopReason::MaxStepsReached, $result->stopReason);
        self::assertFalse($result->completed());
        self::assertNull($result->answer);
        self::assertSame(3, $result->steps);
        self::assertCount(3, $provider->requests());
        self::assertSame(1, $provider->remaining());
    }

    public function test_tool_exception_message_is_passed_to_the_model(): void
    {
        $registry = new ToolRegistry([
            $this->tool('find_student', null, [], static fn () => throw new ToolException('No student with id S-9.')),
        ]);
        $provider = new FakeProvider(
            ChatResponse::toolCalls(new ToolCall('c1', 'find_student', '{"id":"S-9"}')),
            ChatResponse::text('Not found.'),
        );

        $result = (new Agent($provider, $registry))->ask($this->teacher(), 'x');

        self::assertSame(ToolOutcome::Failed, $result->invocations[0]->outcome);
        self::assertErrorCode('tool_error', $result->invocations[0]->output);
        self::assertStringContainsString('No student with id S-9.', $result->invocations[0]->output);
    }

    public function test_unexpected_tool_failures_hide_internal_details(): void
    {
        $registry = new ToolRegistry([
            $this->tool('report', null, [], static fn () => throw new RuntimeException('SQLSTATE[42S02] table secret_x missing')),
        ]);
        $provider = new FakeProvider(
            ChatResponse::toolCalls(new ToolCall('c1', 'report', '{}')),
            ChatResponse::text('Something went wrong.'),
        );

        $result = (new Agent($provider, $registry))->ask($this->teacher(), 'x');
        $invocation = $result->invocations[0];

        self::assertErrorCode('tool_failed', $invocation->output);
        self::assertStringNotContainsString('SQLSTATE', $invocation->output);
        self::assertInstanceOf(RuntimeException::class, $invocation->exception);
        self::assertStringContainsString('SQLSTATE', $invocation->exception->getMessage());
    }

    public function test_tool_handler_receives_the_actor(): void
    {
        $seen = null;
        $registry = new ToolRegistry([
            new CallableTool('whoami', 'Who am I?', [], static function (array $args, Actor $actor) use (&$seen): string {
                $seen = $actor;

                return (string) $actor->id;
            }),
        ]);
        $provider = new FakeProvider(
            ChatResponse::toolCalls(new ToolCall('c1', 'whoami', '')),
            ChatResponse::text('You are t-1.'),
        );
        $actor = $this->teacher();

        (new Agent($provider, $registry))->ask($actor, 'x');

        self::assertSame($actor, $seen);
    }

    public function test_system_prompt_is_not_duplicated_on_follow_up_turns(): void
    {
        $provider = new FakeProvider(ChatResponse::text('First.'), ChatResponse::text('Second.'));
        $agent = new Agent($provider, $this->registry, 'Be brief.');

        $first = $agent->ask($this->teacher(), 'One');
        $second = $agent->run($this->teacher(), [...$first->messages, Message::user('Two')]);

        $roles = array_map(static fn (Message $m): Role => $m->role, $second->messages);
        self::assertSame([Role::System, Role::User, Role::Assistant, Role::User, Role::Assistant], $roles);
        self::assertSame('Second.', $second->answer);
    }

    public function test_provider_errors_propagate(): void
    {
        $this->expectException(ProviderException::class);

        (new Agent(new FakeProvider(), $this->registry))->ask($this->teacher(), 'x');
    }

    public function test_max_steps_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Agent(new FakeProvider(), $this->registry, maxSteps: 0);
    }

    private function teacher(): Actor
    {
        return new Actor('t-1', ['teacher'], ['attendance.view']);
    }

    /**
     * @param list<string>                          $required
     * @param callable(array<string, mixed>): mixed $handler
     */
    private function tool(string $name, ?string $permission, array $required, callable $handler): Tool
    {
        $schema = ['type' => 'object', 'properties' => []];

        foreach ($required as $field) {
            $schema['properties'][$field] = ['type' => 'string'];
            $schema['required'][] = $field;
        }

        return new CallableTool($name, 'Test tool ' . $name . '.', $schema, function (array $args) use ($name, $handler): mixed {
            $this->calls[$name][] = $args;

            return $handler($args);
        }, $permission);
    }

    private static function assertErrorCode(string $expected, string $output): void
    {
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['error'] ?? null);
        self::assertSame($expected, $decoded['error']['code'] ?? null);
    }
}
