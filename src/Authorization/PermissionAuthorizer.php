<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Authorization;

use Ahmed29345\LlmToolCalling\Actor;
use Ahmed29345\LlmToolCalling\Tool;

/**
 * Default policy: the actor needs the tool's required permission, if it has one.
 */
final class PermissionAuthorizer implements Authorizer
{
    public function allows(Actor $actor, Tool $tool): bool
    {
        return $actor->can($tool->requiredPermission());
    }
}
