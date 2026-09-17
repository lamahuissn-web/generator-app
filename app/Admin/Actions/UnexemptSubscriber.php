<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Take a house off the exempt list: the next cycle bills it again.
 *
 * A one-click path guarded to an exempt subscriber; the service records who removed the
 * exemption and what it said, since an exemption that cannot be audited is an exemption that
 * invites dispute.
 */
class UnexemptSubscriber extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Subscriber::class;
    }

    public function label(): string
    {
        return __('a.unexempt');
    }

    public function icon(): ?string
    {
        return 'ki-shield-cross';
    }

    public function triggerClass(): string
    {
        return 'kt-btn-outline';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.unexempt_heading', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        return __('a.unexempt_desc');
    }

    public function submitLabel(): string
    {
        return __('a.unexempt');
    }

    public function visible(Model $subject): bool
    {
        return $subject->isExempt() && (bool) auth()->user()?->isAdmin();
    }

    public function sections(): array
    {
        return [];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Subscriber $subject */
        BillingService::unexemptSubscriber($subject, auth()->id());

        return ['ok' => true, 'message' => __('a.unexempt_ok', ['name' => $subject->name])];
    }
}
