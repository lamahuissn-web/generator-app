<?php

namespace App\Services;

use App\Models\Reading;
use App\Models\Subscriber;
use App\Models\User;

/**
 * Recording a meter reading — the operator's daily operation.
 *
 * This dispatches to BillingService; it exists because a reading is recorded in one of three
 * MODES and which mode decides which fields matter and which service call runs. That
 * translation used to live inside the panel resource that rendered it, which meant the
 * workflow would have been deleted along with the markup.
 *
 * The three modes, and why they are not one field with a "reason" text box:
 *  - NORMAL:   the meter number speaks for itself.
 *  - ESTIMATED: the meter is dead. The operator's kWh is what gets billed, and it becomes the
 *              new baseline — so there is no catch-up when the meter is replaced. The estimate
 *              reason is recorded on the reading because a month later nobody remembers why.
 *  - SWAP:     a new meter is fitted. The old meter's final reading closes the old contract and
 *              the new meter starts from its own number, so the two counters can never be
 *              subtracted from each other.
 */
final class ReadingService
{
    public const MODE_NORMAL = 'normal';

    public const MODE_ESTIMATED = 'estimated';

    public const MODE_SWAP = 'swap';

    /** Mode options for a select, in the order they are offered. */
    public static function modeOptions(): array
    {
        return [
            self::MODE_NORMAL => __('t.mode_normal'),
            self::MODE_ESTIMATED => __('t.mode_estimated'),
            self::MODE_SWAP => __('t.mode_swap'),
        ];
    }

    public static function modes(): array
    {
        return array_keys(self::modeOptions());
    }

    /**
     * Record the reading described by $data.
     *
     * @param  array<string, mixed>  $data  reading_mode plus the fields of that mode
     * @return array{reading: Reading, bill: \App\Models\Bill}
     *
     * @throws \RuntimeException from the billing service (a refused reading is shown, not swallowed)
     */
    public static function record(Subscriber $subscriber, array $data, ?int $userId = null): array
    {
        $period = (string) $data['period'];

        // The suspicious-reading gate (spec 013) reaches every path that records a reading, this one
        // included. On a panel screen the OFFICE is the reviewer: the person typing is the one who would
        // otherwise be asked, so asking him to confirm himself is ceremony — and the reading board's
        // «قراءات مشكوكة» list still shows what came through. A phone collector is a different case: his
        // reading is gated, and the app asks him at the meter.
        $confirmed = (bool) ($data['confirmed_anomaly'] ?? false)
            || (User::query()->find($userId)?->isAdmin() ?? false);

        return match ($data['reading_mode'] ?? self::MODE_NORMAL) {
            self::MODE_ESTIMATED => BillingService::enterEstimatedReading(
                $subscriber,
                $period,
                (float) $data['estimated_kwh'],
                ($data['estimate_reason'] ?? null) ?: null,
                $userId,
                // The reading screen may state the extension explicitly (the office's action on a
                // dead-meter case); the plain estimate mode never does, so the one-estimate policy
                // holds on every screen that offers it.
                (bool) ($data['estimate_extension'] ?? false),
            ),

            self::MODE_SWAP => BillingService::enterMeteredReading(
                $subscriber,
                $period,
                (float) $data['current_reading'],
                $userId,
                ($data['note'] ?? null) ?: null,
                [
                    'swap' => [
                        'old_meter_no' => ($data['old_meter_no'] ?? null) ?: null,
                        'old_reading' => (float) $data['old_meter_reading'],
                        'new_meter_no' => $data['new_meter_no'],
                        'new_start' => (float) ($data['new_meter_start'] ?? 0),
                    ],
                    'confirmed_anomaly' => $confirmed,
                ],
            ),

            default => BillingService::enterMeteredReading(
                $subscriber,
                $period,
                (float) $data['current_reading'],
                $userId,
                ($data['note'] ?? null) ?: null,
                ['confirmed_anomaly' => $confirmed],
            ),
        };
    }

    /** One line describing what was just recorded, for the confirmation. */
    public static function summary(Reading $reading, float $total, float $debt): string
    {
        $parts = [__('t.reading_ok_body', ['total' => money_ltr($total), 'debt' => money_ltr($debt)])];

        if ($reading->is_estimated) {
            $parts[] = __('t.reading_ok_estimated', ['kwh' => number_format((float) $reading->consumption, 1)]);
        }

        if ($reading->hasMeterSwap()) {
            $parts[] = __('t.reading_ok_swap', ['label' => $reading->meterSwapLabel()]);
        }

        return implode(' ', $parts);
    }

    /**
     * The fields of the mode currently selected.
     *
     * Shared by the standalone screen and the action on a subscriber's profile, so both entry
     * points offer exactly the same three modes and cannot drift apart.
     *
     * @param  bool  $withSubject  false when the subject is already fixed by the screen
     * @return array<int, array<string, mixed>>
     */
    public static function modeFields(bool $withSubject = true, ?Subscriber $subscriber = null): array
    {
        $fields = [];

        if ($withSubject) {
            // A search box beside the picker: a network has hundreds of subscribers and a
            // native select of that length is unusable. The option list follows the search.
            $fields[] = [
                'key' => 'subscriber_search',
                'label' => __('t.find_subscriber'),
                'type' => 'search',
                'helper' => __('t.find_subscriber_hint'),
            ];

            $fields[] = [
                'key' => 'subscriber_id',
                'label' => __('t.subscriber'),
                'type' => 'select',
                'rules' => ['required', 'integer', 'exists:subscribers,id'],
                'options_from' => function (array $state): array {
                    $subscriber = Subscriber::query()
                        ->when(
                            trim((string) ($state['subscriber_search'] ?? '')) !== '',
                            fn ($query) => $query->where(function ($query) use ($state) {
                                $term = '%'.trim((string) $state['subscriber_search']).'%';
                                $query->where('name', 'like', $term)
                                    ->orWhere('meter_no', 'like', $term)
                                    ->orWhere('phone', 'like', $term);
                            }),
                        )
                        ->orderBy('name')
                        ->limit(200)
                        ->get();

                    // The currently selected subscriber always stays in the list: the select
                    // is re-rendered on every keystroke, and a chosen value that vanishes from
                    // the options resets the field to blank on the next save.
                    $selected = $state['subscriber_id'] ?? null;
                    if ($selected && ! $subscriber->contains('id', (int) $selected)) {
                        $subscriber->push(Subscriber::find((int) $selected));
                    }

                    return $subscriber->filter()->pluck('name', 'id')->all();
                },
            ];
        }

        $previousHint = function (array $state) use ($subscriber): ?string {
            $subject = $subscriber ?? Subscriber::find($state['subscriber_id'] ?? null);
            $period = (string) ($state['period'] ?? now()->format('Y-m'));

            if (! $subject) {
                return null;
            }

            if ($subject->readingFor($period)) {
                return __('t.reading_exists_hint', ['period' => period_label_i18n($period)]);
            }

            return __('t.previous_value_hint', ['value' => number_format(BillingService::previousValue($subject, $period), 0)]);
        };

        $fields[] = [
            'key' => 'period',
            'label' => __('t.period_ym'),
            'default' => now()->format('Y-m'),
            'placeholder' => now()->format('Y-m'),
            'helper' => __('s.period_hint'),
            'rules' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];

        $fields[] = [
            'key' => 'reading_mode',
            'label' => __('t.reading_mode'),
            'type' => 'select',
            'default' => self::MODE_NORMAL,
            'options' => self::modeOptions(),
            'helper' => __('t.reading_mode_hint'),
            'rules' => ['required', 'in:'.implode(',', self::modes())],
        ];

        $fields[] = [
            'key' => 'current_reading',
            'label' => __('t.cur_meter_reading'),
            'type' => 'number',
            'min' => 0,
            'helper_from' => $previousHint,
            'visible_when' => fn (array $state): bool => ($state['reading_mode'] ?? self::MODE_NORMAL) !== self::MODE_ESTIMATED,
            'required_when' => fn (array $state): bool => ($state['reading_mode'] ?? self::MODE_NORMAL) !== self::MODE_ESTIMATED,
            'rules' => ['nullable', 'numeric', 'min:0'],
        ];

        $fields[] = [
            'key' => 'estimated_kwh',
            'label' => __('t.estimated_kwh'),
            'type' => 'number',
            'min' => 0,
            // The one-estimate policy is stated where the number is typed: the refusal below
            // would arrive after the admin had already decided the amount, and the useful
            // moment to say "this case needs a decision" is before that.
            'helper_from' => function (array $state) use ($subscriber): ?string {
                $subject = $subscriber ?? Subscriber::find($state['subscriber_id'] ?? null);

                if (! $subject?->hasBrokenMeterReport() || $subject->mayEstimateAgain()) {
                    return __('t.estimated_kwh_hint');
                }

                return __('t.estimate_policy_hint', [
                    'since' => period_label_i18n($subject->meter_broken_reported_at->format('Y-m')),
                    'used' => $subject->brokenEstimatesUsed(),
                ]);
            },
            'visible_when' => fn (array $state): bool => ($state['reading_mode'] ?? null) === self::MODE_ESTIMATED,
            'required_when' => fn (array $state): bool => ($state['reading_mode'] ?? null) === self::MODE_ESTIMATED,
            'rules' => ['nullable', 'numeric', 'min:0'],
        ];

        $fields[] = [
            'key' => 'estimate_reason',
            'label' => __('t.estimate_reason'),
            'default' => __('t.estimate_reason_default'),
            'visible_when' => fn (array $state): bool => ($state['reading_mode'] ?? null) === self::MODE_ESTIMATED,
            'required_when' => fn (array $state): bool => ($state['reading_mode'] ?? null) === self::MODE_ESTIMATED,
            'rules' => ['nullable', 'string', 'max:160'],
        ];

        $swapOnly = fn (array $state): bool => ($state['reading_mode'] ?? null) === self::MODE_SWAP;

        $fields[] = [
            'key' => 'old_meter_no',
            'label' => __('t.old_meter_no'),
            'helper' => __('t.old_meter_no_hint'),
            'visible_when' => $swapOnly,
            'rules' => ['nullable', 'string', 'max:40'],
        ];

        $fields[] = [
            'key' => 'old_meter_reading',
            'label' => __('t.old_meter_final'),
            'type' => 'number',
            'min' => 0,
            'helper_from' => $previousHint,
            'visible_when' => $swapOnly,
            'required_when' => $swapOnly,
            'rules' => ['nullable', 'numeric', 'min:0'],
        ];

        $fields[] = [
            'key' => 'new_meter_no',
            'label' => __('t.new_meter_no'),
            'visible_when' => $swapOnly,
            'required_when' => $swapOnly,
            'rules' => ['nullable', 'string', 'max:40'],
        ];

        $fields[] = [
            'key' => 'new_meter_start',
            'label' => __('t.new_meter_start'),
            'type' => 'number',
            'min' => 0,
            'default' => 0,
            'helper' => __('t.new_meter_start_hint'),
            'visible_when' => $swapOnly,
            'required_when' => $swapOnly,
            'rules' => ['nullable', 'numeric', 'min:0'],
        ];

        $fields[] = [
            'key' => 'note',
            'label' => __('t.note'),
            'span' => 'full',
            'rules' => ['nullable', 'string', 'max:200'],
        ];

        return $fields;
    }

    /**
     * The labels of a mode's own fields, for the required-marker and the hint under each.
     * Kept beside modeFields() so a rename cannot update one and miss the other.
     */
    public static function modeLabel(string $mode): string
    {
        return self::modeOptions()[$mode] ?? $mode;
    }
}
