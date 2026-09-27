<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Refuelling;
use App\Services\CycleLock;
use App\Services\EquipmentLog;
use Illuminate\Database\Eloquent\Model;

/**
 * حذف تعبئة — a row that should not exist takes its figures with it.
 *
 * Offered on an OPEN month only. In a month CycleLock has closed the row is corrected instead, with a reason
 * in words: deleting alone would quietly rewrite a month that was already reported and collected against with
 * nothing written down, which is the same distinction the readings list makes between this morning's mistake
 * and June's.
 *
 * A refuelling has no expense of its own to clean up — `refuellings.expense_id` is never written by the add
 * path — so this removes one row, inside one transaction, and audits the values that went.
 */
class RemoveRefuelling extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Refuelling::class;
    }

    public function label(): string
    {
        return __('a.del_refuel');
    }

    public function icon(): ?string
    {
        return 'ki-trash';
    }

    public function triggerClass(): string
    {
        return 'kt-btn-destructive';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.del_refuel_heading', ['date' => $subject->date?->toDateString()]);
    }

    public function modalDescription(): ?string
    {
        return __('a.del_refuel_desc');
    }

    public function submitLabel(): string
    {
        return __('a.del_refuel');
    }

    /** Admin, and only while the month is still open — a closed month offers the correction instead. */
    public function visible(Model $subject): bool
    {
        return (bool) auth()->user()?->isAdmin()
            && ! CycleLock::isClosed($subject->date?->format('Y-m'));
    }

    public function sections(): array
    {
        return [];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Refuelling $subject */
        $removed = EquipmentLog::removeRefuelling($subject, null);

        return ['ok' => true, 'message' => __('a.del_refuel_ok', [
            'litres' => number_format((float) $removed['litres'], 0),
        ])];
    }
}
