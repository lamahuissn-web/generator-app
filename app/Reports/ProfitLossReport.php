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
 * Ported out of the Filament page so the query lives in one place: the panel is being
 * rebuilt on Metronic and the old page will be deleted, but the definition of "profit" must
 * not change with the markup.
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

    /** The month options the picker offers: 18 back from today, oldest first. */
    public static function monthOptions(): array
    {
        $options = [];

        for ($i = 17; $i >= 0; $i--) {
            $date = now()->startOfMonth()->subMonths($i);
            $options[$date->format('Y-m')] = period_label_i18n($date->format('Y-m'));
        }

        return $options;
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
