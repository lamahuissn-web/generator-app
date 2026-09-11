<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Reading;
use App\Services\BillingService;
use App\Services\CycleLock;
use Illuminate\Database\Eloquent\Model;

/**
 * Correcting a CLOSED cycle: remove a reading that has already been invoiced and collected against.
 *
 * This exists as its own action rather than a text box on the ordinary delete because the two are
 * different acts. Deleting this morning's mistake is housekeeping; deleting June's reading re-opens
 * a closed month, changes a subscriber's history and removes an invoice that may have been printed
 * and handed over. So it asks for a reason, in words, and the reason lands in the audit trail
 * beside the figures that were removed.
 *
 * Only reachable from a reading in a closed cycle: the ordinary delete is hidden on those rows and
 * this one is hidden on the rest, so the operator never has to guess which one they are looking at.
 */
class CorrectLockedReading extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Reading::class;
    }

    public function label(): string
    {
        return __('a.correct_locked');
    }

    public function icon(): ?string
    {
        return 'ki-shield-cross';
    }

    public function triggerClass(): string
    {
        return 'kt-btn-outline kt-btn-destructive';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.correct_locked_heading', [
            'name' => $subject->subscriber?->name ?? '',
            'period' => period_label_i18n($subject->period),
        ]);
    }

    public function modalDescription(): ?string
    {
        return __('a.correct_locked_desc');
    }

    public function submitLabel(): string
    {
        return __('a.del_reading');
    }

    /** Only a closed cycle: an open one has the ordinary delete right beside it. */
    public function visible(Model $subject): bool
    {
        return $subject instanceof Reading && CycleLock::isClosed($subject->period);
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => [
                [
                    'key' => 'reason',
                    'label' => __('t.reason'),
                    'helper' => __('a.correct_locked_reason_hint'),
                    'rules' => ['required', 'string', 'max:160'],
                ],
            ],
        ]];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Reading $subject */
        BillingService::deleteReadingAndBill($subject, (string) $state['reason']);

        return ['ok' => true, 'message' => __('a.correct_locked_ok', [
            'period' => period_label_i18n($subject->period),
        ])];
    }
}
