<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling;

use Ahmed29345\LlmToolCalling\Authorization\Authorizer;
use Ahmed29345\LlmToolCalling\Authorization\PermissionAuthorizer;
use InvalidArgumentException;

/**
 * Holds every tool the application offers and answers which of them a given actor may use.
 */
final class ToolRegistry
{
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @var array<string, Tool> */
    private array $tools = [];

    private readonly Authorizer $authorizer;

    /**
     * @param iterable<Tool> $tools
     */
    public function __construct(iterable $tools = [], ?Authorizer $authorizer = null)
    {
        $this->authorizer = $authorizer ?? new PermissionAuthorizer();

        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    /**
     * @throws InvalidArgumentException when the name is invalid or already registered
     */
    public function register(Tool $tool): self
    {
        $name = $tool->name();

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Tool name "%s" is invalid: use 1-64 letters, digits, "_" or "-".',
                $name,
            ));
        }

        if (isset($this->tools[$name])) {
            throw new InvalidArgumentException(sprintf('A tool named "%s" is already registered.', $name));
        }

        $this->tools[$name] = $tool;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<Tool>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * The tools this actor may see and call. Only these are sent to the model.
     *
     * @return list<Tool>
     */
    public function forActor(Actor $actor): array
    {
        return array_values(array_filter(
            $this->tools,
            fn (Tool $tool): bool => $this->allows($actor, $tool),
        ));
    }

    public function allows(Actor $actor, Tool $tool): bool
    {
        return $this->authorizer->allows($actor, $tool);
    }
}
