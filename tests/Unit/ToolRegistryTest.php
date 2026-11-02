<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Tests\Unit;

use Ahmed29345\LlmToolCalling\Actor;
use Ahmed29345\LlmToolCalling\Authorization\Authorizer;
use Ahmed29345\LlmToolCalling\CallableTool;
use Ahmed29345\LlmToolCalling\Tool;
use Ahmed29345\LlmToolCalling\ToolRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    public function test_for_actor_returns_only_permitted_tools(): void
    {
        $registry = new ToolRegistry([
            self::tool('public_info'),
            self::tool('attendance_summary', 'attendance.view'),
            self::tool('unpaid_invoices', 'finance.view'),
        ]);

        $teacher = new Actor('t', ['teacher'], ['attendance.view']);
        $accountant = new Actor('a', ['accountant'], ['finance.view']);
        $guest = new Actor('g');

        self::assertSame(['public_info', 'attendance_summary'], self::names($registry->forActor($teacher)));
        self::assertSame(['public_info', 'unpaid_invoices'], self::names($registry->forActor($accountant)));
        self::assertSame(['public_info'], self::names($registry->forActor($guest)));
        self::assertCount(3, $registry->all());
    }

    public function test_custom_authorizer_replaces_the_permission_check(): void
    {
        $onlyAdmins = new class () implements Authorizer {
            public function allows(Actor $actor, Tool $tool): bool
            {
                return $actor->hasRole('admin');
            }
        };

        $registry = new ToolRegistry([self::tool('public_info')], $onlyAdmins);

        self::assertSame([], $registry->forActor(new Actor(1, ['teacher'])));
        self::assertSame(['public_info'], self::names($registry->forActor(new Actor(2, ['admin']))));
    }

    public function test_lookup_by_name(): void
    {
        $tool = self::tool('lookup');
        $registry = (new ToolRegistry())->register($tool);

        self::assertTrue($registry->has('lookup'));
        self::assertSame($tool, $registry->get('lookup'));
        self::assertNull($registry->get('missing'));
    }

    public function test_duplicate_names_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        new ToolRegistry([self::tool('same'), self::tool('same')]);
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_are_rejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry([self::tool($name)]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['unpaid invoices'];
        yield 'dot' => ['finance.view'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    private static function tool(string $name, ?string $permission = null): Tool
    {
        return new CallableTool($name, 'Test tool.', [], static fn (): string => 'ok', $permission);
    }

    /**
     * @param list<Tool> $tools
     *
     * @return list<string>
     */
    private static function names(array $tools): array
    {
        return array_map(static fn (Tool $tool): string => $tool->name(), $tools);
    }
}
