<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling;

use Closure;

/**
 * A Tool built from a closure, for small tools that do not need their own class.
 */
final class CallableTool implements Tool
{
    /** @var Closure(array<string, mixed>, Actor): mixed */
    private readonly Closure $handler;

    /**
     * @param array<string, mixed> $parameters JSON Schema for the arguments object
     * @param callable(array<string, mixed>, Actor): mixed $handler
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $parameters,
        callable $handler,
        private readonly ?string $permission = null,
    ) {
        $this->handler = $handler(...);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function parameters(): array
    {
        return $this->parameters;
    }

    public function requiredPermission(): ?string
    {
        return $this->permission;
    }

    public function handle(array $arguments, Actor $actor): mixed
    {
        return ($this->handler)($arguments, $actor);
    }
}
