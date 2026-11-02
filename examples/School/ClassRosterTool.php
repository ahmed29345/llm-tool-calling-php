<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Examples\School;

use Ahmed29345\LlmToolCalling\Actor;
use Ahmed29345\LlmToolCalling\Exception\ToolException;
use Ahmed29345\LlmToolCalling\Tool;

/**
 * Lists the students in a class. Needs no permission.
 */
final class ClassRosterTool implements Tool
{
    public function name(): string
    {
        return 'class_roster';
    }

    public function description(): string
    {
        return 'List the students (id and name) in a class. Use it to resolve a student name to an id.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'enum' => SchoolData::classes()],
            ],
            'required' => ['class'],
        ];
    }

    public function requiredPermission(): ?string
    {
        return null;
    }

    public function handle(array $arguments, Actor $actor): mixed
    {
        $class = $arguments['class'];

        if (!in_array($class, SchoolData::classes(), true)) {
            throw new ToolException('Unknown class. Valid classes: ' . implode(', ', SchoolData::classes()) . '.');
        }

        $students = [];

        foreach (SchoolData::STUDENTS as $id => $student) {
            if ($student['class'] === $class) {
                $students[] = ['id' => $id, 'name' => $student['name']];
            }
        }

        return ['class' => $class, 'students' => $students];
    }
}
