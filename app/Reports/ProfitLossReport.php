<?php

namespace App\Reports;

use App\Models\Bill;
use App\Models\Expense;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * One month of money in and money out.
 *
 * The query lives here, not in the screen, so the definition of "profit" does not depend on
 * which markup is displaying it.
 *
 * Two deliberate choices, both carried over unchanged:
 *  - money IN is cash actually collected in the window (Payment.created_at), not what was
 *    invoiced — invoicing is a promise, collection is the fact.
 *  - money OUT is expenses by their own expense_date, not when they were entered.
 */
final class ProfitLossReport
{
    public function __construct(private readonly string $month) {}

    /** @param string $month 'Y-m' */
    public static function forMonth(string $month): self
    {
        return new self($month);
    }

    public function month(): string
    {
        return $this->month;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $start = Carbon::parse($this->month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $invoiced = (float) Bill::where('period', $this->month)->sum('total');
        $collected = (float) Payment::whereBetween('created_at', [$start, $end])->sum('amount');
        $expensesTotal = (float) Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->sum('amount');

        return [
            'month' => $this->month,
            'label' => period_label_i18n($this->month),
            'invoiced' => $invoiced,
            'collected' => $collected,
            'rate' => $invoiced > 0 ? round($collected / $invoiced * 100, 1) : 0.0,
            'expenses' => $expensesTotal,
            'net' => round($collected - $expensesTotal, 2),
            'byCategory' => $this->byCategory($start, $end),
            'recentExpenses' => $this->recentExpenses($start, $end),
        ];
    }

    /** @return Collection<int, array{label: string, count: int, total: float}> */
    private function byCategory(Carbon $start, Carbon $end): Collection
    {
        return Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('category, COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'key' => $row->category,
                'label' => Expense::label($row->category),
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ])
            ->values();
    }

    private function recentExpenses(Carbon $start, Carbon $end): Collection
    {
        return Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->latest('expense_date')
            ->limit(8)
            ->get(['expense_date', 'category', 'description', 'amount']);
    }
}
