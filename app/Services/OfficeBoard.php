<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Subscriber;
use Carbon\Carbon;

/**
 * The office's board: the month's billing, what came in against it, and what is still out.
 *
 * Extracted from the admin dashboard so the panel and the manager's phone cannot hold two versions of
 * the same figure — «نسبة التحصيل» in particular, a number two people will compare out loud. The
 * dashboard keeps its own labels, icons and tones; the NUMBERS come from here.
 *
 * Three of these expressions carry office rules that must not be re-invented anywhere:
 *
 *  - **collected means the day the money was collected**, not the day it was typed (`collectedInRange`),
 *    which is what makes the rate a statement about the month rather than about the office's typing;
 *  - **exempt houses are outside the chase**: the office said it does not claim from them, so their old
 *    debt is not part of what the panel is working on (feature 004);
 *  - **what sits on STOPPED accounts is reported separately** — it is collected the same way, but money
 *    nobody is chasing is money the office cannot decide about unseen.
 */
class OfficeBoard
{
    /** @return array<string, mixed> */
    public static function numbers(string $period): array
    {
        $month = Carbon::createFromFormat('Y-m', $period);
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        $previous = $month->copy()->subMonthNoOverflow();
        $prevFrom = $previous->copy()->startOfMonth()->toDateString();
        $prevTo = $previous->copy()->endOfMonth()->toDateString();

        $invoiced = (float) Bill::query()->where('period', $period)->sum('total');

        $collected = (float) Payment::query()->collectedInRange($from, $to)->sum('amount');

        $outstanding = (float) Bill::query()
            ->whereHas('subscriber', fn ($query) => $query->whereNull('exempt_at'))
            ->whereRaw('paid_amount < total')
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as o')
            ->value('o');

        $inactiveOutstanding = (float) Bill::query()
            ->whereHas('subscriber', fn ($query) => $query
                ->where('status', Subscriber::STATUS_INACTIVE)
                ->whereNull('exempt_at'))
            ->whereRaw('paid_amount < total')
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as o')
            ->value('o');

        $active = Subscriber::query()->active();
        $activeCount = (clone $active)->count();
        $meteredCount = (clone $active)->metered()->count();

        $kwh = (float) Bill::query()->where('period', $period)->sum('consumption');

        $prevInvoiced = (float) Bill::query()->where('period', $previous->format('Y-m'))->sum('total');
        $prevCollected = (float) Payment::query()->collectedInRange($prevFrom, $prevTo)->sum('amount');

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'invoiced' => $invoiced,
            'collected' => $collected,
            'rate' => $invoiced > 0 ? round($collected / $invoiced * 100, 1) : 0.0,
            'prev_period' => $previous->format('Y-m'),
            'prev_invoiced' => $prevInvoiced,
            'prev_collected' => $prevCollected,
            'prev_rate' => $prevInvoiced > 0 ? round($prevCollected / $prevInvoiced * 100, 1) : 0.0,
            'outstanding' => $outstanding,
            'outstanding_inactive' => $inactiveOutstanding,
            'active' => $activeCount,
            'metered' => $meteredCount,
            'kwh' => $kwh,
            'tariff' => BillingService::kwhPrice($period),
            'tariff_source' => BillingService::priceSource($period),
        ];
    }
}
