<?php

namespace App\Services;

use App\Models\CashHandover;
use Illuminate\Support\Facades\DB;

class CashHandoverService
{
    /**
     * Cash this collector could hand over right now.
     *
     * When an existing handover is being edited it is counted in the in-hand figure already, so
     * its old amount has to be added back — the operator is replacing that record, not adding a
     * second one. Only for the same collector: a record moved to another collector never touched
     * the new collector's cash.
     */
    public static function availableFor(int $collectorId, ?CashHandover $ignore = null): float
    {
        $inHand = CollectionService::inHand($collectorId);

        if ($ignore && (int) $ignore->collector_id === $collectorId) {
            $inHand = round($inHand + (float) $ignore->amount, 2);
        }

        return $inHand;
    }

    /**
     * A collector cannot hand over cash they are not holding.
     *
     * The in-hand figure is what the operator reads off the ledger and reconciles against the
     * drawer; letting a handover exceed it drives that balance negative, and a negative balance
     * is not a real position — it silently overstates what the collector still owes and every
     * total built on it (the ledger, the collection report, the cash summary) is then wrong.
     */
    private static function assertCollectorHolds(int $collectorId, float $amount, ?CashHandover $ignore = null): void
    {
        $available = self::availableFor($collectorId, $ignore);

        if (round($amount, 2) > $available) {
            throw new \RuntimeException(
                __('refuse.handover_exceeds_in_hand', ['amount' => money_ltr($available)])
            );
        }
    }

    /** Record cash handed over by a collector. */
    public static function create(array $data, ?int $userId = null): CashHandover
    {
        return DB::transaction(function () use ($data, $userId) {
            $collectorId = (int) $data['collector_id'];
            $amount = round((float) $data['amount'], 2);

            self::assertCollectorHolds($collectorId, $amount);

            $handover = CashHandover::create([
                'collector_id' => $collectorId,
                'amount' => $amount,
                'handover_date' => $data['handover_date'],
                'note' => $data['note'] ?? null,
                'recorded_by' => $userId ?? auth()->id(),
            ]);

            Audit::log('handover.created', $handover, [
                'collector_id' => $handover->collector_id,
                'amount' => (float) $handover->amount,
                'handover_date' => $handover->handover_date->toDateString(),
                'in_hand_after' => CollectionService::inHand($handover->collector_id),
            ]);

            return $handover;
        });
    }

    /** Update an existing handover (typo in the amount / wrong collector). */
    public static function update(CashHandover $handover, array $data): CashHandover
    {
        return DB::transaction(function () use ($handover, $data) {
            $before = [
                'collector_id' => $handover->collector_id,
                'amount' => (float) $handover->amount,
                'handover_date' => $handover->handover_date->toDateString(),
            ];

            $collectorId = (int) $data['collector_id'];
            $amount = round((float) $data['amount'], 2);

            // Checked against what is available WITH this record taken back out — an edit must be
            // able to raise a handover back up to the full in-hand figure.
            self::assertCollectorHolds($collectorId, $amount, $handover);

            $handover->update([
                'collector_id' => $collectorId,
                'amount' => $amount,
                'handover_date' => $data['handover_date'],
                'note' => $data['note'] ?? null,
            ]);

            Audit::log('handover.updated', $handover, [
                'before' => $before,
                'after' => [
                    'collector_id' => $handover->collector_id,
                    'amount' => (float) $handover->amount,
                    'handover_date' => $handover->handover_date->toDateString(),
                ],
                'in_hand_after' => CollectionService::inHand($handover->collector_id),
            ]);

            return $handover;
        });
    }

    /** Delete a handover that was recorded by mistake. */
    public static function delete(CashHandover $handover): void
    {
        DB::transaction(function () use ($handover) {
            $snapshot = [
                'collector_id' => $handover->collector_id,
                'amount' => (float) $handover->amount,
                'handover_date' => $handover->handover_date->toDateString(),
                'note' => $handover->note,
            ];

            Audit::log('handover.deleted', $handover, [
                ...$snapshot,
                'in_hand_after' => round(CollectionService::inHand($handover->collector_id) + (float) $handover->amount, 2),
            ]);

            $handover->delete();
        });
    }
}
