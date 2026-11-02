<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling;

/**
 * The user on whose behalf the agent is running.
 *
 * Permissions are plain strings such as "finance.view". A grant of "*" allows
 * everything, and a grant ending in ".*" (for example "finance.*") allows every
 * permission under that prefix. Resolve roles to permissions in your own
 * application before building the actor; roles are kept here so tools can
 * still make role-aware decisions inside handle().
 */
final readonly class Actor
{
    /** @var list<string> */
    public array $roles;

    /** @var list<string> */
    public array $permissions;

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        public string|int $id,
        array $roles = [],
        array $permissions = [],
    ) {
        $this->roles = array_values(array_unique($roles));
        $this->permissions = array_values(array_unique($permissions));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * A null permission means "no permission required" and is always allowed.
     */
    public function can(?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        foreach ($this->permissions as $granted) {
            if ($granted === $permission || $granted === '*') {
                return true;
            }

            if (str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
