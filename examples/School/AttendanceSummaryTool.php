<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Examples\School;

use Ahmed29345\LlmToolCalling\Actor;
use Ahmed29345\LlmToolCalling\Tool;

/**
 * This week's attendance for a class. Needs "attendance.view".
 */
final class AttendanceSummaryTool implements Tool
{
    public function name(): string
    {
        return 'attendance_summary';
    }

    public function description(): string
    {
        return "Summarise this week's attendance for a class: days present, absent and late per student.";
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

    public function requiredPermission(): string
    {
        return 'attendance.view';
    }

    public function handle(array $arguments, Actor $actor): mixed
    {
        $rows = [];

        foreach (SchoolData::STUDENTS as $id => $student) {
            if ($student['class'] !== $arguments['class']) {
                continue;
            }

            $days = array_count_values(SchoolData::attendance($id));
            $rows[] = [
                'student' => $student['name'],
                'present' => $days['P'] ?? 0,
                'absent' => $days['A'] ?? 0,
                'late' => $days['L'] ?? 0,
            ];
        }

        return ['class' => $arguments['class'], 'school_days' => 5, 'students' => $rows];
    }
}
