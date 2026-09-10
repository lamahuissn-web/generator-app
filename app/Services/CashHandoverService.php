<?php

namespace App\Services;

use App\Models\CashHandover;
use Illuminate\Support\Facades\DB;

class CashHandoverService
{
    /** Record cash handed over by a collector. */
    public static function create(array $data, ?int $userId = null): CashHandover
    {
        return DB::transaction(function () use ($data, $userId) {
            $handover = CashHandover::create([
                'collector_id' => $data['collector_id'],
                'amount' => round((float) $data['amount'], 2),
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

            $handover->update([
                'collector_id' => $data['collector_id'],
                'amount' => round((float) $data['amount'], 2),
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
