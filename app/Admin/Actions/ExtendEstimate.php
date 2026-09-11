<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Subscriber;
use App\Services\BillingService;
use App\Services\ReadingService;
use Illuminate\Database\Eloquent\Model;

/**
 * The office's deliberate exception: bill one more estimated month while the meter is still dead.
 *
 * The policy allows one estimate per report, and after that the case is a decision — replace the
 * meter, move the subscriber to a flat rate, or extend. Only the last of those keeps the meter
 * portfolio unchanged, so it is the one that has to be typed out: an amount, a cycle and a
 * reason, with the admin's name on it.
 *
 * This exists rather than a looser policy because the alternative — refusing outright — has no
 * humane answer when the technician is two weeks late: the office would be forced to bill a flat
 * rate it does not want, or leave a house unbilled. A logged exception is better than a workaround,
 * and much better than a rule nobody notices is being broken.
 */
class ExtendEstimate extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Subscriber::class;
    }

    public function label(): string
    {
        return __('a.extend_estimate');
    }

    public function icon(): ?string
    {
        return 'ki-time';
    }

    public function triggerClass(): string
    {
        // kt-btn-outline + kt-btn-destructive: the pair the app already uses for "this is not the
        // normal path" (the field's dead-meter report). There is no kt-btn-warning in KTUI — the
        // same class of dead reference as an unknown ki-* glyph, and just as invisible.
        return 'kt-btn-outline kt-btn-destructive';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.extend_estimate_heading', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        return __('a.extend_estimate_desc');
    }

    public function submitLabel(): string
    {
        return __('a.extend_estimate');
    }

    /** Only inside an open dead-meter case: this is about the case, not about estimating generally. */
    public function visible(Model $subject): bool
    {
        return $subject->isMetered()
            && $subject->status === Subscriber::STATUS_ACTIVE
            && $subject->hasBrokenMeterReport();
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => [
                [
                    'key' => 'period',
                    'label' => __('t.period_ym'),
                    'default' => now()->format('Y-m'),
                    'helper' => __('s.period_hint'),
                    'rules' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
                ],
                [
                    'key' => 'estimated_kwh',
                    'label' => __('t.estimated_kwh'),
                    'type' => 'number',
                    'min' => 0,
                    'helper' => __('t.estimated_kwh_hint'),
                    'rules' => ['required', 'numeric', 'min:0'],
                ],
                [
                    'key' => 'reason',
                    'label' => __('t.reason'),
                    'default' => __('t.reason_broken_meter'),
                    'helper' => __('a.extend_estimate_reason_hint'),
                    'rules' => ['required', 'string', 'max:160'],
                ],
            ],
        ]];
    }

    public function defaults(?Model $subject = null): array
    {
        return [
            'period' => now()->format('Y-m'),
            'estimated_kwh' => null,
            'reason' => __('t.reason_broken_meter'),
        ];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Subscriber $subject */
        $result = BillingService::enterEstimatedReading(
            $subject,
            (string) $state['period'],
            (float) $state['estimated_kwh'],
            (string) $state['reason'],
            auth()->id(),
            true,
        );

        return [
            'ok' => true,
            'message' => ReadingService::summary(
                $result['reading'],
                (float) $result['bill']->total,
                (float) $result['bill']->prev_debt,
            ),
        ];
    }
}
