<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Mark a subscriber as exempt: no invoice, no claim — with the reason recorded.
 *
 * A mosque and a relative are not stopped subscribers. This is a standing decision: the
 * meter keeps telling the truth about the network, and only the money stops. Shown as a
 * row action so the operator can exempt directly from the list; the form holds the same
 * control for an edit, and the service refuses an empty reason either way.
 */
class ExemptSubscriber extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Subscriber::class;
    }

    public function label(): string
    {
        return __('a.exempt');
    }

    public function icon(): ?string
    {
        return 'ki-shield-tick';
    }

    public function triggerClass(): string
    {
        return '';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.exempt_heading', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        return __('a.exempt_desc');
    }

    public function submitLabel(): string
    {
        return __('a.exempt');
    }

    public function visible(Model $subject): bool
    {
        return ! $subject->isExempt() && (bool) auth()->user()?->isAdmin();
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => [
                [
                    'key' => 'exempt_reason',
                    'label' => __('s.exempt_reason'),
                    'type' => 'textarea',
                    'span' => 'full',
                    'helper' => __('s.exempt_reason_hint'),
                    'rules' => ['required', 'string', 'max:200'],
                ],
                [
                    'key' => 'exempt_category',
                    'label' => __('s.exempt_category'),
                    'type' => 'select',
                    'placeholder' => __('t.select_placeholder'),
                    'options_from' => fn (): array => discount_category_options(),
                    'rules' => ['nullable', 'string', 'max:40'],
                ],
            ],
        ]];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Subscriber $subject */
        BillingService::exemptSubscriber(
            $subject,
            (string) ($state['exempt_reason'] ?? ''),
            ($state['exempt_category'] ?? '') !== '' ? (string) $state['exempt_category'] : null,
            auth()->id(),
        );

        return ['ok' => true, 'message' => __('a.exempt_ok', ['name' => $subject->name])];
    }
}
