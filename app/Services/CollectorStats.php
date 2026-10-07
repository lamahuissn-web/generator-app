<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Payment;
use App\Models\Reading;
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

    /**
     * His last receipts, newest first — the lines behind the cash in his pocket (spec 011).
     *
     * The list is what he matches his money against at the end of a day, so it is ordered by the BUSINESS
     * date (`collected_on`), the same date the day's total is summed by: a list ordered differently from the
     * figure above it is how a man concludes he is short.
     */
    public static function receipts(User $collector, int $limit = 30): Collection
    {
        return Payment::query()
            ->with('subscriber')
            ->where('collector_id', $collector->id)
            ->orderByRaw('date(coalesce(payments.collected_on, payments.created_at)) desc')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The invoices his own readings produced this cycle (spec 011).
     *
     * The link is the engine's (`bills.reading_id`), not a guess by name or date. A reading that produced no
     * invoice — a meter's baseline, or a cycle the office has not billed yet — is simply absent: the panel
     * says «لا فاتورة» on its own screen, and there is no money in it for this list.
     */
    public static function invoices(User $collector, string $period, int $limit = 20): Collection
    {
        return Bill::query()
            ->with('subscriber')
            ->whereIn('reading_id', Reading::query()
                ->select('id')
                ->where('entered_by', $collector->id)
                ->where('period', $period))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * How many readings this collector wrote: today's work, and the cycle's.
     *
     * Two different questions, both his own: «كم قراءة سجّلت اليوم» is the day, and «كم قراءة لهذا الشهر»
     * is the cycle he is billing for. `entered_by` is the only honest key — a reading he did not enter is
     * not his day, whatever else it is.
     *
     * @return array{today: int, cycle: int}
     */
    public static function readings(User $collector, string $period): array
    {
        return [
            'today' => Reading::query()
                ->where('entered_by', $collector->id)
                ->whereDate('created_at', now()->toDateString())
                ->count(),
            'cycle' => Reading::query()
                ->where('entered_by', $collector->id)
                ->where('period', $period)
                ->count(),
        ];
    }

    /** The last few handovers, newest first — the dates behind `handed_total`. */
    public static function handovers(User $collector, int $limit = 3): Collection
    {
        return CashHandover::query()
            ->with('recordedBy')
            ->where('collector_id', $collector->id)
            ->latest('handover_date')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Today's takings for EVERY collector at once, keyed by collector id.
     *
     * The manager's board needs the same «today» for a whole staff; calling `for()` in a loop would be a
     * handful of queries per collector. One query, one business-date rule, same answer.
     *
     * @return array<int, array{total: float, count: int}>
     */
    public static function todayByCollector(): array
    {
        $businessDate = 'date(coalesce(payments.collected_on, payments.created_at))';

        return Payment::query()
            ->whereRaw("$businessDate = ?", [now()->toDateString()])
            ->selectRaw('collector_id, COUNT(*) as payments_count, COALESCE(SUM(amount), 0) as collected')
            ->groupBy('collector_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->collector_id => [
                    'total' => round((float) $row->collected, 2),
                    'count' => (int) $row->payments_count,
                ],
            ])
            ->all();
    }
}
