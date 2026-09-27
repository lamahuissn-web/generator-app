<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\EquipmentService;
use App\Services\CycleLock;
use App\Services\EquipmentLog;
use Illuminate\Database\Eloquent\Model;

/**
 * حذف صيانة — and the expense it produced goes with it.
 *
 * Offered on an OPEN month only; a closed month's service is corrected instead, with the reason in words that
 * `CycleLock` demands, because that month's costs were already reported. Removing the service removes its
 * expense in the same transaction (`EquipmentLog::removeService()`): a repair that is gone must stop costing
 * the month money, and an orphaned expense row is exactly how a corrected figure keeps being printed.
 */
class RemoveService extends ActionDefinition
{
    public function subjectModel(): string
    {
        return EquipmentService::class;
    }

    public function label(): string
    {
        return __('a.del_service');
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
        return __('a.del_service_heading', ['date' => $subject->date?->toDateString()]);
    }

    public function modalDescription(): ?string
    {
        return __('a.del_service_desc');
    }

    public function submitLabel(): string
    {
        return __('a.del_service');
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
        /** @var EquipmentService $subject */
        $removed = EquipmentLog::removeService($subject, null);

        return ['ok' => true, 'message' => __('a.del_service_ok', [
            'kind' => EquipmentService::kinds()[$removed['kind']] ?? (string) $removed['kind'],
        ])];
    }
}
