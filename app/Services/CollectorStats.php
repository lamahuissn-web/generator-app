<?php

namespace App\Services;

use App\Models\CashHandover;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One collector's own money: what he took today, this month, and what is still in his pocket.
 *
 * Extracted so the office's dashboard and the phone's dashboard cannot drift apart — they were about to
 * be two copies of the same four sums, and a collector reconciling at the end of a day with two
 * different "today" figures has been handed a dispute, not a report.
 *
 * **The date is the BUSINESS date** (`collected_on`, falling back to `created_at` for rows written
 * before that column existed), not the moment somebody typed it. It is the same date the receipt is
 * numbered by, the same date the reports group by, and the same date the handover reads: a receipt
 * written at a door on the 3rd and synced on the 4th is the 3rd's money. The panel's dashboard used to
 * sum `created_at`, which quietly agreed only while every phone was online.
 */
class CollectorStats
{
    /** @return array<string, mixed> */
    public static function for(User $collector): array
    {
        // `date(...)` around the pair, not just the fallback: a collector's date column may be stored
        // with a time on it, and «2026-10-05 00:00:00» is not the string «2026-10-05».
        $businessDate = 'date(coalesce(payments.collected_on, payments.created_at))';

        $today = Payment::query()
            ->where('collector_id', $collector->id)
            ->whereRaw("$businessDate = ?", [now()->toDateString()]);

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $month = Payment::query()
            ->where('collector_id', $collector->id)
            ->whereRaw("$businessDate >= ? and $businessDate <= ?", [$monthStart, $monthEnd]);

        return [
            'today_total' => round((float) (clone $today)->sum('amount'), 2),
            'today_count' => (clone $today)->count(),
            'month_total' => round((float) (clone $month)->sum('amount'), 2),
            'month_count' => (clone $month)->count(),
            // Cash still in his pocket: everything collected ever, minus everything handed over.
            'in_hand' => CollectionService::inHand($collector->id),
            'handed_total' => CollectionService::handedOverAllTime($collector->id),
        ];
    }

    /** The last few handovers, newest first — the dates behind `handed_total`. */
    public static function handovers(User $collector, int $limit = 3): Collection
    {
        return CashHandover::query()
            ->where('collector_id', $collector->id)
            ->latest('handover_date')
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
