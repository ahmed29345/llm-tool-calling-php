<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Authorization;

use Ahmed29345\LlmToolCalling\Actor;
use Ahmed29345\LlmToolCalling\Tool;

/**
 * Decides whether an actor may use a tool.
 *
 * The same decision is used twice: when choosing which tool definitions the
 * model is allowed to see, and again right before a requested tool runs.
 * Implement this to plug in your framework's policy layer.
 */
interface Authorizer
{
    public function allows(Actor $actor, Tool $tool): bool;
}
