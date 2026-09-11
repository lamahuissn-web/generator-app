<?php

namespace App\Services;

use App\Models\Expense;

class ExpenseService
{
    /** Create an expense with audit trail. */
    public static function create(array $data, ?int $userId = null): Expense
    {
        $expense = Expense::create([...$data, 'entered_by' => $userId ?? auth()->id()]);

        Audit::log('expense.created', $expense, [
            'category' => $expense->category,
            'amount' => (float) $expense->amount,
            'expense_date' => $expense->expense_date->toDateString(),
        ]);

        return $expense;
    }

    /** Delete an expense with audit trail. */
    /**
     * Edit an expense, audited like its creation.
     *
     * The old values go into the audit payload: without them "who changed the fuel price"
     * is unanswerable, and an expense edit is exactly the kind of change that gets questioned
     * at month end.
     */
    public static function update(Expense $expense, array $data): Expense
    {
        $before = [
            'category' => $expense->category,
            'amount' => (float) $expense->amount,
            'expense_date' => $expense->expense_date->toDateString(),
            'quantity' => $expense->quantity,
            'description' => $expense->description,
        ];

        $expense->fill($data)->save();

        Audit::log('expense.updated', $expense, [
            'before' => $before,
            'after' => [
                'category' => $expense->category,
                'amount' => (float) $expense->amount,
                'expense_date' => $expense->expense_date->toDateString(),
                'quantity' => $expense->quantity,
                'description' => $expense->description,
            ],
        ]);

        return $expense;
    }

    public static function delete(Expense $expense): void
    {
        $snapshot = [
            'category' => $expense->category,
            'amount' => (float) $expense->amount,
            'expense_date' => $expense->expense_date->toDateString(),
            'receipt' => $expense->receipt_path,
        ];

        Audit::log('expense.deleted', $expense, $snapshot);

        $expense->delete();
    }
}
