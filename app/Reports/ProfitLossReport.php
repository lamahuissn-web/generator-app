<?php

namespace App\Reports;

use App\Models\Bill;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Subscriber;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * One month of money in and money out.
 *
 * The query lives here, not in the screen, so the definition of "profit" does not depend on
 * which markup is displaying it.
 *
 * Three deliberate choices:
 *  - money IN is cash actually collected in the window (Payment.created_at), not what was
 *    invoiced — invoicing is a promise, collection is the fact.
 *  - what was EARNED is the part of that cash which settled invoices (payments.applied, falling
 *    back to the whole receipt for rows written before the column existed). The rest is the
 *    subscriber's own balance, which the office is holding and will spend on his next invoice —
 *    a liability, never income. Profit is earned minus spent, so this figure decides the month.
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

        $from = $start->toDateString();
        $to = $end->toDateString();

        // Cash and earnings are counted on the day the money was COLLECTED, not the day it was typed
        // in: a receipt written on the 2nd for the 31st is the earlier month's money. This is the
        // scope the collection reports already use (Payment::scopeCollectedInRange).
        $collected = (float) Payment::query()->collectedInRange($from, $to)->sum('amount');

        // What of that cash was actually the office's. A receipt carries the money that settled
        // invoices AND the money that is simply the subscriber's — the collector takes a whole
        // number and the difference waits on his account — so the two are read apart, or the office
        // counts a liability as income and prints a profit it cannot touch.
        $earned = (float) Payment::query()->collectedInRange($from, $to)
            ->selectRaw('COALESCE(SUM(COALESCE(applied, amount)), 0) as earned')
            ->value('earned');
        $heldForSubscribers = (float) Subscriber::query()
            ->selectRaw('COALESCE(SUM(credit), 0) as held')
            ->value('held');
        $creditAdded = round($collected - $earned, 2);
        $creditSpent = (float) Bill::where('period', $this->month)
            ->selectRaw('COALESCE(SUM(credit_applied), 0) as used')
            ->value('used');

        $expensesTotal = (float) Expense::whereBetween('expense_date', [$from, $to])
            ->sum('amount');

        return [
            'month' => $this->month,
            'label' => period_label_i18n($this->month),
            'invoiced' => $invoiced,
            'collected' => $collected,
            'earned' => $earned,
            'held_for_subscribers' => $heldForSubscribers,
            'credit_added' => $creditAdded,
            'credit_spent' => $creditSpent,
            'rate' => $invoiced > 0 ? round($collected / $invoiced * 100, 1) : 0.0,
            'expenses' => $expensesTotal,
            'net' => round($earned - $expensesTotal, 2),
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
