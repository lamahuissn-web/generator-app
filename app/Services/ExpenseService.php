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
