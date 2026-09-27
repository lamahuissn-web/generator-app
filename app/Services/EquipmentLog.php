<?php

namespace App\Services;

use App\Admin\Actions\RecordService;
use App\Models\EquipmentService;
use App\Models\Expense;
use App\Models\Refuelling;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * تصحيح تعبئة أو صيانة — correcting what was written into a device's log, and taking it back.
 *
 * A refuelling is not a line in a diary. It is the only input of four figures the board publishes
 * (كم كيلوواط أنتج · الفاقد ونسبته · كلفة الكيلوواط · ربح الكيلوواط) and of two numbers on the device page
 * (موعد الصيانة القادم from its engine hours, the litres-per-hour anomaly). A service is worse: it carries
 * money, because `equipment_services.expense_id` is the row the month's costs are actually read from — the
 * expense is the single money record and the log only points at it.
 *
 * So there is no edit-in-place here. A correction REPLACES the row: the old one is removed and the new one
 * written inside one transaction, the linked expense moves with it (or goes with it), and the pair lands in
 * the audit trail as before → after. That is the rule this app already wrote down for a reading
 * (`specs/RISKS.md` §3 — «Editing a reading in a closed cycle: no path overwrites an existing figure;
 * correction is delete-then-re-enter»), because an edit screen invites an edit that leaves the old bill
 * behind. Here the "bill" is a month's production and, for a service, an expense row.
 *
 * The closed-month gate is `CycleLock`'s — the same one a reading's correction and the collection screens
 * use, so there is ONE definition of "a month that is over" in the app. Both write paths are admin-only:
 * a figure a customer has already seen on paper is not housekeeping.
 */
final class EquipmentLog
{
    /** Replace a refuelling with its corrected values. Returns the row that now stands. */
    public static function correctRefuelling(Refuelling $old, array $state, ?string $reason = null): Refuelling
    {
        $period = self::periodOf($old->date);
        self::gate($period, $reason);

        $measured = filled($state['kwh_produced'] ?? null) && (float) $state['kwh_produced'] > 0;

        // Zero is not a measurement (the add path's own rule): it means he did not write the kWh down, so
        // the row stores nothing and stays «تقديري» instead of claiming the machine produced nothing.
        $after = [
            'date' => $state['date'],
            'litres' => (float) $state['litres'],
            'price_per_litre' => (float) $state['price_per_litre'],
            'engine_hours' => filled($state['engine_hours'] ?? null) ? (float) $state['engine_hours'] : null,
            'kwh_produced' => $measured ? (float) $state['kwh_produced'] : null,
            'kwh_source' => $measured ? Refuelling::SOURCE_MEASURED : Refuelling::SOURCE_ESTIMATED,
            'notes' => ($state['notes'] ?? null) ?: null,
        ];

        $before = self::refuellingSnapshot($old);

        return DB::transaction(function () use ($old, $after, $before, $reason, $period): Refuelling {
            // The row's own money link travels with the values: `refuellings.expense_id` is never written by
            // the add path, and a correction must not invent one (or lose one an office wrote by hand).
            $replacement = Refuelling::create($after + [
                'equipment_id' => $old->equipment_id,
                'expense_id' => $old->expense_id,
                'created_by' => auth()->id(),
            ]);

            $old->delete();

            Audit::log('refuelling.corrected', $replacement, [
                'before' => $before,
                'after' => $after,
                'replaced_row_id' => $old->id,
                'cycle_closed' => CycleLock::isClosed($period),
                'reason' => $reason,
            ]);

            return $replacement;
        });
    }

    /** Take a refuelling off the log. Returns the values that were removed. */
    public static function removeRefuelling(Refuelling $row, ?string $reason = null): array
    {
        $period = self::periodOf($row->date);
        self::gate($period, $reason);

        $before = self::refuellingSnapshot($row);

        DB::transaction(function () use ($row, $before, $reason, $period): void {
            $row->delete();

            Audit::log('refuelling.removed', $row, [
                'removed' => $before,
                'cycle_closed' => CycleLock::isClosed($period),
                'reason' => $reason,
            ]);
        });

        return $before;
    }

    /**
     * Replace a service with the corrected one, moving its expense in the same transaction.
     *
     * The expense is the money record, so it is the thing that must not be left behind: a service corrected
     * from 40.00 to 65.00 with the expense still reading 40.00 leaves the month's `net` believing a figure
     * the office has just corrected.
     */
    public static function correctService(EquipmentService $old, array $state, ?string $reason = null): EquipmentService
    {
        $period = self::periodOf($old->date);
        self::gate($period, $reason);

        $cost = filled($state['cost'] ?? null) ? round((float) $state['cost'], 2) : 0.0;
        $kind = (string) $state['kind'];

        $after = [
            'date' => $state['date'],
            'kind' => $kind,
            'engine_hours' => filled($state['engine_hours'] ?? null) ? (float) $state['engine_hours'] : null,
            'cost' => $cost,
            'notes' => ($state['notes'] ?? null) ?: null,
        ];

        $before = self::serviceSnapshot($old);
        $device = $old->equipment?->name ?? '';

        return DB::transaction(function () use ($old, $after, $before, $cost, $kind, $device, $state, $reason, $period): EquipmentService {
            $expenseId = self::syncExpense(
                $old->expense,
                $kind,
                (string) $after['date'],
                $cost,
                (bool) ($state['record_expense'] ?? true),
                $device,
            );

            $replacement = EquipmentService::create($after + [
                'equipment_id' => $old->equipment_id,
                'expense_id' => $expenseId,
                'created_by' => auth()->id(),
            ]);

            $old->delete();

            Audit::log('service.corrected', $replacement, [
                'before' => $before,
                'after' => $after,
                // What the money did, named separately: the log row and its expense are two rows, and an
                // audit that only shows the service's cost would hide a deleted expense.
                'expense_id' => $expenseId,
                'expense_amount_before' => $before['expense_amount'],
                'replaced_row_id' => $old->id,
                'cycle_closed' => CycleLock::isClosed($period),
                'reason' => $reason,
            ]);

            return $replacement;
        });
    }

    /** Take a service off the log — and the expense it produced with it. */
    public static function removeService(EquipmentService $row, ?string $reason = null): array
    {
        $period = self::periodOf($row->date);
        self::gate($period, $reason);

        $before = self::serviceSnapshot($row);

        DB::transaction(function () use ($row, $before, $reason, $period): void {
            // A service that is gone cannot still cost the month money: leaving the expense behind would
            // report a repair the office has just said did not happen.
            self::dropExpense($row->expense);

            $row->delete();

            Audit::log('service.removed', $row, [
                'removed' => $before,
                'expense_removed' => $before['expense_amount'] !== null,
                'cycle_closed' => CycleLock::isClosed($period),
                'reason' => $reason,
            ]);
        });

        return $before;
    }

    /**
     * The one rule about a service's money, used by both paths.
     *
     * Wanted (a cost and the box ticked) → the expense is updated in place, or created. Not wanted → the
     * expense is DELETED, never left orphaned beside a service that no longer costs anything. Updating in
     * place keeps `expenses.entered_by` as the office's own record of who first entered it; who CORRECTED it
     * is the audit trail's job.
     */
    private static function syncExpense(
        ?Expense $expense,
        string $kind,
        string $date,
        float $cost,
        bool $record,
        string $device,
    ): ?int {
        if ($cost > 0.0 && $record) {
            $values = [
                'category' => RecordService::expenseCategory($kind),
                'description' => __('eq.service_expense_note', [
                    'device' => $device,
                    'kind' => EquipmentService::kinds()[$kind] ?? $kind,
                ]),
                'amount' => $cost,
                'expense_date' => $date,
            ];

            if ($expense) {
                $expense->fill($values)->save();

                return $expense->id;
            }

            return Expense::create($values + ['entered_by' => auth()->id()])->id;
        }

        return self::dropExpense($expense);
    }

    private static function dropExpense(?Expense $expense): ?int
    {
        if ($expense) {
            $expense->delete();
        }

        return null;
    }

    /**
     * The gate both paths pass, in one place so the modal and the service refuse in the same words.
     *
     * Admin first: `CycleLock::refusal()` returns null for an OPEN month whatever the user, because an open
     * month needs no ceremony — so the admin rule cannot ride on it and is checked here.
     */
    private static function gate(string $period, ?string $reason): void
    {
        if (! (auth()->user()?->isAdmin() ?? false)) {
            throw new \RuntimeException(__('refuse.admin_only'));
        }

        if ($refusal = CycleLock::refusal($period, $reason, auth()->user())) {
            throw new \RuntimeException($refusal);
        }
    }

    private static function periodOf(mixed $date): string
    {
        return $date instanceof \DateTimeInterface
            ? $date->format('Y-m')
            : (string) $date;
    }

    /** @return array<string, mixed> */
    private static function refuellingSnapshot(Refuelling $row): array
    {
        return [
            'date' => $row->date?->toDateString(),
            'litres' => $row->litres,
            'price_per_litre' => $row->price_per_litre,
            'engine_hours' => $row->engine_hours,
            'kwh_produced' => $row->kwh_produced,
            'kwh_source' => $row->kwh_source,
            'notes' => $row->notes,
        ];
    }

    /** @return array<string, mixed> */
    private static function serviceSnapshot(EquipmentService $row): array
    {
        return [
            'date' => $row->date?->toDateString(),
            'kind' => $row->kind,
            'engine_hours' => $row->engine_hours,
            'cost' => $row->cost,
            'notes' => $row->notes,
            // The money's own before-figure, so an audit reader can see the expense move (or vanish).
            'expense_id' => $row->expense_id,
            'expense_amount' => $row->expense?->amount,
        ];
    }
}
