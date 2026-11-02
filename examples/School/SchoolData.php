<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Examples\School;

/**
 * In-memory stand-in for a school database. All names and figures are made up.
 */
final class SchoolData
{
    /** @var array<string, array{name: string, class: string}> */
    public const STUDENTS = [
        'S-101' => ['name' => 'Aisha Khan', 'class' => 'Grade 3A'],
        'S-102' => ['name' => 'Omar Siddiqui', 'class' => 'Grade 3A'],
        'S-103' => ['name' => 'Lena Fischer', 'class' => 'Grade 3A'],
        'S-201' => ['name' => 'Yusuf Rahman', 'class' => 'Grade 4B'],
        'S-202' => ['name' => 'Mia Torres', 'class' => 'Grade 4B'],
    ];

    /**
     * Attendance for the current week: P (present), A (absent), L (late).
     *
     * @var array<string, list<'P'|'A'|'L'>>
     */
    public const ATTENDANCE = [
        'S-101' => ['P', 'P', 'P', 'P', 'P'],
        'S-102' => ['P', 'A', 'A', 'P', 'L'],
        'S-103' => ['P', 'P', 'L', 'P', 'P'],
        'S-201' => ['A', 'P', 'P', 'P', 'P'],
        'S-202' => ['P', 'P', 'P', 'P', 'P'],
    ];

    /** @var list<array{invoice: string, student: string, amount: int, currency: string, due: string, paid: bool}> */
    public const INVOICES = [
        ['invoice' => 'INV-1001', 'student' => 'S-101', 'amount' => 450, 'currency' => 'USD', 'due' => '2026-10-01', 'paid' => true],
        ['invoice' => 'INV-1002', 'student' => 'S-102', 'amount' => 450, 'currency' => 'USD', 'due' => '2026-10-01', 'paid' => false],
        ['invoice' => 'INV-1003', 'student' => 'S-201', 'amount' => 520, 'currency' => 'USD', 'due' => '2026-10-15', 'paid' => false],
        ['invoice' => 'INV-1004', 'student' => 'S-202', 'amount' => 520, 'currency' => 'USD', 'due' => '2026-10-15', 'paid' => true],
    ];

    /**
     * @return list<'P'|'A'|'L'>
     */
    public static function attendance(string $studentId): array
    {
        return self::ATTENDANCE[$studentId] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function classes(): array
    {
        return array_values(array_unique(array_column(self::STUDENTS, 'class')));
    }
}
