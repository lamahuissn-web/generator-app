<?php

namespace App\Reports;

use App\Models\Reading;
use App\Models\Subscriber;
use App\Services\BillingService;
use App\Services\ReadingQuality;
use Illuminate\Support\Collection;

/**
 * Dead meters, as cases: what was reported, what has been done about it, and what is due next.
 *
 * Without this the app scattered the three answers across the panel — an estimate hidden inside the
 * reading modal's mode picker, a replacement inside the same picker, a flat rate on the subscriber's
 * profile — and no screen ever said "this meter is dead and nobody has decided". A dead meter then
 * lives for months in a state where every screen looks normal, because the report used to be cleared
 * by the first recorded cycle whether or not the device was fixed.
 *
 * A case is open from the field report to the point where the DEVICE changed or was read working.
 * The state is what the office owes it:
 *
 *  - NEW:      reported, no estimated cycle billed yet. The case was answered by nothing so far, and
 *              the cycle is waiting (a working meter's reading is the cheapest way out).
 *  - DECISION: one estimated cycle is in, which is the whole allowance. The next estimated month, a
 *              replacement or a flat rate is a choice somebody has to make and sign.
 *
 * The kWh average is shown as INFORMATION only. The office chose that the estimate is always an
 * entered number — the system does not decide what a household consumed because a meter stopped.
 */
final class MeterCaseBoard
{
    /** Reported, and no estimated cycle has been billed against it yet. */
    public const STATE_NEW = 'new';

    /** An estimated cycle is in: the policy's allowance is spent, the device is still dead. */
    public const STATE_DECISION = 'decision';

    public const STATE_ALL = 'all';

    private ?Collection $allCache = null;

    public function __construct(
        private readonly ?int $routeId = null,
        private readonly string $state = self::STATE_ALL,
    ) {}

    public static function make(?int $routeId = null, string $state = self::STATE_ALL): self
    {
        return new self($routeId, $state);
    }

    /** @return array<string, string> */
    public static function stateOptions(): array
    {
        return [
            self::STATE_ALL => __('mc.state_all'),
            self::STATE_NEW => __('mc.state_new'),
            self::STATE_DECISION => __('mc.state_decision'),
        ];
    }

    /** Months from $period up to $current, both 'Y-m'. */
    private static function monthsSince(string $period): int
    {
        return ReadingBoard::monthsBetween(now()->format('Y-m'), $period);
    }

    /** Every open case, newest report last. */
    private function all(): Collection
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }

        $subscribers = Subscriber::query()
            ->metered()
            ->active()
            ->whereNotNull('meter_broken_reported_at')
            ->with(['route:id,name', 'meterBrokenReportedBy:id,name'])
            ->when($this->routeId, fn ($query) => $query->where('route_id', $this->routeId))
            ->orderBy('meter_broken_reported_at')
            ->get();

        $ids = $subscribers->pluck('id')->all();

        // Every reading for these houses, newest first: the newest REAL one is the last thing
        // anybody measured, and the newest overall is the value a new reading continues from.
        $readings = Reading::query()
            ->whereIn('subscriber_id', $ids)
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->get([
                'id', 'subscriber_id', 'period', 'current_reading', 'previous_reading',
                'consumption', 'is_estimated',
            ])
            ->groupBy('subscriber_id');

        $thisCycle = Reading::query()
            ->whereIn('subscriber_id', $ids)
            ->where('period', now()->format('Y-m'))
            ->pluck('is_estimated', 'subscriber_id');

        $rows = $subscribers->map(function (Subscriber $subscriber) use ($readings, $thisCycle): array {
            /** @var Collection<int, Reading> $history */
            $history = $readings->get($subscriber->id) ?? collect();
            /** @var ?Reading $newest */
            $newest = $history->first();
            $lastReal = $history->first(fn (Reading $reading): bool => ! $reading->is_estimated);

            $casePeriod = $subscriber->meter_broken_reported_at->format('Y-m');
            $used = $subscriber->brokenEstimatesUsed();

            return [
                'id' => $subscriber->id,
                'name' => $subscriber->name,
                'route' => $subscriber->route?->name,
                'phone' => $subscriber->phone,
                'meter_no' => $subscriber->meter_no,
                'amperage' => $subscriber->amperage,
                'note' => $subscriber->meter_broken_note,
                // The name of the person who SAW the meter: the case is a conversation between the
                // field and the office, and the office needs to know who to ask.
                'reported_by' => $subscriber->meterBrokenReportedBy?->name,
                'reported_at' => $subscriber->meter_broken_reported_at,
                'case_period' => $casePeriod,
                'age' => self::monthsSince($casePeriod),
                'estimates_used' => $used,
                'last_real_period' => $lastReal?->period,
                'last_real_consumption' => $lastReal === null ? null : (float) $lastReal->consumption,
                // Information for the person deciding, never a pre-filled amount.
                'average' => ReadingQuality::average($subscriber, now()->format('Y-m')),
                'previous' => (float) ($newest?->current_reading ?? 0),
                'cycle_read' => $thisCycle->has($subscriber->id),
                'cycle_estimated' => $thisCycle->has($subscriber->id)
                    ? (bool) $thisCycle->get($subscriber->id)
                    : null,
                'outstanding' => $subscriber->outstanding(),
                'state' => $used === 0 ? self::STATE_NEW : self::STATE_DECISION,
                'subscriber' => $subscriber,
            ];
        });

        return $this->allCache = $rows
            // Oldest case first: the one that has been broken longest is the one losing the most.
            ->sortBy([
                fn (array $a, array $b) => $b['age'] <=> $a['age'],
                fn (array $a, array $b) => strcmp((string) $a['name'], (string) $b['name']),
            ])
            ->values();
    }

    public function rows(): Collection
    {
        return $this->all()
            ->when($this->state !== self::STATE_ALL, fn (Collection $rows) => $rows->where('state', $this->state))
            ->values();
    }

    /** Counted over every open case, not the filtered list: a filter narrows a view, not the truth. */
    public function summary(): array
    {
        $all = $this->all();

        return [
            'total' => $all->count(),
            'decision' => $all->where('state', self::STATE_DECISION)->count(),
            'estimates' => (int) $all->sum('estimates_used'),
            'oldest' => $all->max('age'),
            'unread_cycle' => $all->where('cycle_read', false)->count(),
        ];
    }

    /** The list as a spreadsheet, for working the cases by phone. */
    public function csv(): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            __('t.name'), __('t.route'), __('t.meter'), __('t.phone'),
            __('mc.reported_at'), __('mc.reported_by'), __('mc.current_state'), __('mc.estimates'),
        ]);

        foreach ($this->rows() as $row) {
            fputcsv($handle, [
                $row['name'],
                $row['route'] ?? '',
                $row['meter_no'] ?? '',
                $row['phone'] ?? '',
                $row['case_period'],
                $row['reported_by'] ?? '',
                $row['state'] === self::STATE_NEW ? __('mc.chip_new') : __('mc.chip_decision'),
                (string) $row['estimates_used'],
            ]);
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /** How a case is introduced in one line: since when, and how much has been estimated since. */
    public function caseLabel(array $row): string
    {
        return __('mc.case_line', [
            'period' => period_label_i18n($row['case_period']),
            'used' => $row['estimates_used'],
        ]);
    }

    /** What the office owes this case next — the sentence the screen is built around. */
    public function nextStepLabel(array $row): string
    {
        return match ($row['state']) {
            self::STATE_NEW => $row['cycle_read']
                ? __('mc.next_new_read')
                : __('mc.next_new_open'),
            default => __('mc.next_decision', ['period' => period_label_i18n($row['case_period'])]),
        };
    }
}
