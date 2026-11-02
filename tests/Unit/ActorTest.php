<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Tests\Unit;

use Ahmed29345\LlmToolCalling\Actor;
use PHPUnit\Framework\TestCase;

final class ActorTest extends TestCase
{
    public function test_no_required_permission_is_always_allowed(): void
    {
        self::assertTrue((new Actor(1))->can(null));
    }

    public function test_exact_permission_match(): void
    {
        $actor = new Actor(1, permissions: ['attendance.view']);

        self::assertTrue($actor->can('attendance.view'));
        self::assertFalse($actor->can('attendance.edit'));
        self::assertFalse($actor->can('finance.view'));
    }

    public function test_prefix_wildcard_covers_only_its_namespace(): void
    {
        $actor = new Actor(1, permissions: ['finance.*']);

        self::assertTrue($actor->can('finance.view'));
        self::assertTrue($actor->can('finance.invoices.refund'));
        self::assertFalse($actor->can('finance'));
        self::assertFalse($actor->can('financex.view'));
        self::assertFalse($actor->can('attendance.view'));
    }

    public function test_global_wildcard_allows_everything(): void
    {
        self::assertTrue((new Actor(1, permissions: ['*']))->can('anything.at.all'));
    }

    public function test_roles_are_deduplicated_and_queryable(): void
    {
        $actor = new Actor('u-1', ['teacher', 'teacher', 'parent']);

        self::assertSame(['teacher', 'parent'], $actor->roles);
        self::assertTrue($actor->hasRole('parent'));
        self::assertFalse($actor->hasRole('admin'));
    }
}
