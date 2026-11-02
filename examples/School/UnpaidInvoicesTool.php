<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Examples\School;

use Ahmed29345\LlmToolCalling\Actor;
use Ahmed29345\LlmToolCalling\Tool;

/**
 * Outstanding fee invoices. Needs "finance.view": a teacher's assistant never sees this tool.
 */
final class UnpaidInvoicesTool implements Tool
{
    public function name(): string
    {
        return 'unpaid_invoices';
    }

    public function description(): string
    {
        return 'List unpaid fee invoices with student, amount and due date, optionally for one class.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => [
                    'type' => 'string',
                    'enum' => SchoolData::classes(),
                    'description' => 'Limit to one class. Omit for the whole school.',
                ],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'finance.view';
    }

    public function handle(array $arguments, Actor $actor): mixed
    {
        $class = is_string($arguments['class'] ?? null) ? $arguments['class'] : null;
        $unpaid = [];

        foreach (SchoolData::INVOICES as $invoice) {
            $student = SchoolData::STUDENTS[$invoice['student']];

            if ($invoice['paid'] || ($class !== null && $student['class'] !== $class)) {
                continue;
            }

            $unpaid[] = [
                'invoice' => $invoice['invoice'],
                'student' => $student['name'],
                'class' => $student['class'],
                'amount' => $invoice['amount'],
                'currency' => $invoice['currency'],
                'due' => $invoice['due'],
            ];
        }

        return ['count' => count($unpaid), 'invoices' => $unpaid];
    }
}
