<?php

namespace App\Livewire;

use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Services\BillingService;
use App\Services\ReadingQuality;
use Livewire\Component;

/**
 * A whole route's meter readings on one screen.
 *
 * The per-subscriber screen is the right shape for one house: it explains the cycle,
 * previews the bill and offers the payment. Walking a route it costs a page load per
 * house. This screen inverts it: every metered subscriber on the route is a row with the
 * meter value ready to type, Enter moves to the next row, and one save records every
 * filled row — each in its OWN transaction, so a row the meter refuses (a value below the
 * previous reading, a reading already taken this cycle) is reported under its name
 * instead of costing the collector the other twenty.
 *
 * A blank row is not an error: it is a house nobody answered. It stays pending, which is
 * exactly the state the route list shows for it afterwards.
 *
 * Shared by both portals: the collector's route screen (fixed to one area, this cycle) and the
 * office's entry screen (any area, any cycle). One implementation, because a second one would
 * drift — and the day it drifted the field and the office would be entering readings by different
 * rules.
 */
final class RouteReadingSheet extends Component
{
    public Route $route;

    /**
     * The cycle being read: this month by default. A worklist is about now, but the office reads
     * last month's paper slips in too, so the period is a property rather than a constant.
     *
     * Declared with a default because the office's screen passes it in while the field's does not —
     * a typed property with no default is uninitialised when mount() reads it.
     */
    public string $period = '';

    /**
     * Who is filling this in, for the two links that leave the screen.
     *
     * The sheet is rendered to collectors and to the office, and they do not share a subscriber
     * page (a collector in /admin is bounced back out by middleware, and vice versa). The links
     * are resolved from the signed-in role rather than passed in, so a nested render cannot hand a
     * collector a link that 302s them away from the row they were reading.
     */
    public function isOffice(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function subscriberUrl(int $subscriberId): string
    {
        return $this->isOffice()
            ? route('admin.subscribers.show', $subscriberId)
            : route('collector.subscriber.show', $subscriberId);
    }

    /** Where the back arrow goes: the screen that opened this worklist. */
    public function backUrl(): string
    {
        return $this->isOffice()
            ? route('admin.readings.index')
            : route('collector.route.show', $this->route);
    }

    /** @var array<int|string, string> subscriber id => what the collector typed */
    public array $values = [];

    /** @var array<int, array{name: string, reason: string}> rows of the last save that were refused, by subscriber */
    public array $failed = [];

    public ?int $savedCount = null;

    public ?string $flash = null;

    public string $flashType = 'success';

    /** @var array{rows: array<int, array<string, mixed>>, flat: int, inactive: int}|null */
    private ?array $rosterCache = null;

    private ?float $priceCache = null;

    public function mount(Route $route): void
    {
        $this->route = $route;

        // The period may arrive from the caller (the office reads any cycle); this month is only
        // the default, not an assignment that would overwrite what the screen asked for.
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period)) {
            $this->period = BillingService::currentPeriod();
        }
    }

    // ---- the worklist --------------------------------------------------------

    /**
     * Everything the screen knows about each subscriber, in one pass.
     *
     * Memoised on purpose: rows() runs again after every field the collector leaves, and
     * who is on the route, what their last reading was and what they still owe does not
     * change while they type.
     *
     * @return array{rows: array<int, array<string, mixed>>, flat: int, inactive: int}
     */
    private function roster(): array
    {
        if ($this->rosterCache !== null) {
            return $this->rosterCache;
        }

        $everyone = $this->route->subscribers()->orderBy('name')->get();

        // Only a live metered subscriber can be read. Flat-rate subscribers have no meter
        // and an inactive one must not be billed a cycle they were cut out of, so both are
        // counted and named in the footer instead of being offered an input.
        $metered = $everyone
            ->filter(fn (Subscriber $s): bool => $s->isMetered() && $s->status === Subscriber::STATUS_ACTIVE)
            ->values();

        $ids = $metered->pluck('id')->all();

        // One query for every reading up to this cycle: the newest row per subscriber is
        // either this cycle's reading (already done) or the value the cycle continues from.
        $latest = Reading::query()
            ->whereIn('subscriber_id', $ids)
            ->where('period', '<=', $this->period)
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->get(['id', 'subscriber_id', 'period', 'current_reading', 'previous_reading', 'consumption', 'is_estimated'])
            ->groupBy('subscriber_id');

        // Debt carried into this cycle, and the bill already standing for it (a reading
        // recorded from the office, or from this screen a moment ago).
        $debts = Bill::query()
            ->whereIn('subscriber_id', $ids)
            ->where('period', '<', $this->period)
            ->selectRaw('subscriber_id, COALESCE(SUM(total - paid_amount), 0) as owed')
            ->groupBy('subscriber_id')
            ->pluck('owed', 'subscriber_id');

        $thisCycle = Bill::query()
            ->whereIn('subscriber_id', $ids)
            ->where('period', $this->period)
            ->get(['subscriber_id', 'total', 'prev_debt'])
            ->keyBy('subscriber_id');

        $rows = [];

        foreach ($metered as $subscriber) {
            $newest = $latest->get($subscriber->id)?->first();
            $done = $newest !== null && $newest->period === $this->period;
            $bill = $thisCycle->get($subscriber->id);

            $rows[] = [
                'id' => $subscriber->id,
                // The name is kept beside the model: the blade renders rows out of the
                // array and has no business reaching into the relation for a label.
                'name' => $subscriber->name,
                'meter_no' => $subscriber->meter_no,
                'amperage' => $subscriber->amperage,
                // A done row stores the value it continued from, so the pair shown is the
                // pair that was billed — not a re-derivation that a deleted row would move.
                // A done row stores the value it continued from; an open one asks the same place the
                // invoice will, so the sheet never shows «السابقة: 0» for a meter that reads 12,500.
                'previous' => round(
                    $done ? (float) $newest->previous_reading : BillingService::previousValue($subscriber, $this->period),
                    2,
                ),
                'maintenance' => $subscriber->effectiveMaintenanceFee(),
                'debt' => (float) ($debts[$subscriber->id] ?? 0),
                'done' => $done,
                'recorded' => $done ? [
                    'current' => (float) $newest->current_reading,
                    'consumption' => (float) $newest->consumption,
                    'estimated' => (bool) $newest->is_estimated,
                    'total' => (float) ($bill?->total ?? 0) + (float) ($bill?->prev_debt ?? 0),
                ] : null,
                'broken' => $subscriber->hasBrokenMeterReport(),
                'subscriber' => $subscriber,
            ];
        }

        return $this->rosterCache = [
            'rows' => $rows,
            'flat' => $everyone->filter(fn (Subscriber $s): bool => $s->isFlat())->count(),
            'inactive' => $everyone->filter(
                fn (Subscriber $s): bool => $s->isMetered() && $s->status !== Subscriber::STATUS_ACTIVE
            )->count(),
        ];
    }

    /**
     * The rows the blade paints: pending houses first.
     *
     * The unread ones are the worklist, so they sit at the top and the screen shortens as
     * the collector walks — saved rows drop to the bottom with their confirmed bill.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        $price = $this->price();
        $pending = [];
        $done = [];

        foreach ($this->roster()['rows'] as $row) {
            $value = $this->typed((int) $row['id']);
            $row['preview'] = $value === null ? null : $this->preview($row, $value, $price);

            if ($row['done']) {
                $done[] = $row;
            } else {
                $pending[] = $row;
            }
        }

        return array_merge($pending, $done);
    }

    /** @return array{total: int, done: int, pending: int} */
    public function progress(): array
    {
        $rows = collect($this->roster()['rows']);
        $done = $rows->where('done', true)->count();

        return ['total' => $rows->count(), 'done' => $done, 'pending' => $rows->count() - $done];
    }

    /** kWh price for the cycle — one lookup, reused by every row of the render. */
    public function price(): float
    {
        return $this->priceCache ??= BillingService::kwhPrice($this->period);
    }

    /** How many rows carry a value that is not recorded yet. */
    public function typedCount(): int
    {
        return collect($this->roster()['rows'])
            ->reject(fn (array $row): bool => $row['done'] || $row['broken'])
            ->filter(fn (array $row): bool => $this->typed((int) $row['id']) !== null)
            ->count();
    }

    /** What the typed rows would bill, for the save bar. */
    public function typedTotal(): float
    {
        $price = $this->price();
        $total = 0.0;

        foreach ($this->roster()['rows'] as $row) {
            if ($row['done'] || $row['broken']) {
                continue;
            }
            if (($value = $this->typed((int) $row['id'])) !== null) {
                $total += $this->preview($row, $value, $price)['total'];
            }
        }

        return round($total, 2);
    }

    // ---- writing -------------------------------------------------------------

    /**
     * Record every filled row.
     *
     * One transaction per row, deliberately: the readings are independent records and a
     * duplicate guard on house 7 must not roll back the six houses behind it. Refusals are
     * collected and shown against the row they belong to, and the typed value is kept so
     * the collector can fix it on the spot instead of re-walking the street.
     */
    public function save(): void
    {
        $this->failed = [];
        $this->savedCount = null;
        $this->flash = null;

        $batch = [];
        foreach ($this->roster()['rows'] as $row) {
            if ($row['done'] || $row['broken']) {
                // A reported-dead meter is the office's case: the field neither types a value for
                // it nor guesses one, and its input is not on the screen.
                continue;
            }
            if (($value = $this->typed((int) $row['id'])) !== null) {
                $batch[] = [$row, $value];
            }
        }

        if ($batch === []) {
            $this->flashMessage(__('portal.bulk_none_typed'), 'error');

            return;
        }

        $saved = 0;

        foreach ($batch as [$row, $value]) {
            /** @var Subscriber $subscriber */
            $subscriber = $row['subscriber'];

            // The guard the single-subscriber screen applies: a batch must not be able to
            // do what one house cannot.
            if ($subscriber->status !== Subscriber::STATUS_ACTIVE) {
                $this->failed[(int) $row['id']] = ['name' => $row['name'], 'reason' => __('portal.err_reading_inactive')];

                continue;
            }

            // The sheet shows this row's warning WHILE he types it (see preview()) — so a save with the
            // warning on screen is a save he was warned about, and the gate is satisfied by it (spec 013).
            $warned = ReadingQuality::verdict($subscriber, $this->period, (float) $value) !== null;

            try {
                BillingService::enterMeteredReading(
                    $subscriber,
                    $this->period,
                    $value,
                    auth()->id(),
                    null,
                    ['confirmed_anomaly' => $warned],
                );
                $saved++;
                unset($this->values[$row['id']]);
            } catch (\RuntimeException $e) {
                $this->failed[(int) $row['id']] = ['name' => $row['name'], 'reason' => $e->getMessage()];
            }
        }

        $this->savedCount = $saved;
        // The recorded rows are no longer pending: drop the memo so the next render reads
        // the new state instead of the picture taken before the save.
        $this->rosterCache = null;

        $this->flashMessage(
            $saved > 0 ? plural('portal.bulk_saved', $saved) : __('portal.bulk_none_saved'),
            $saved > 0 ? 'success' : 'error',
        );
    }

    /** Field report from the bulk screen: this meter is dead, the office decides. */
    public function reportBroken(int $subscriberId): void
    {
        if ($this->typed($subscriberId) !== null) {
            // A typed value is a reading in progress; the report is for a meter that cannot
            // answer. Both cannot be true of one house, so the collector picks one.
            $this->flashMessage(__('portal.broken_has_value'), 'error');

            return;
        }

        $subscriber = $this->subject($subscriberId);
        if ($subscriber === null) {
            return;
        }

        $subscriber->reportMeterBroken(null, auth()->id());
        $this->rosterCache = null;
        $this->flashMessage(__('portal.broken_reported'), 'warning');
    }

    /** The meter works after all (or the office took over the case). */
    public function clearBroken(int $subscriberId): void
    {
        $subscriber = $this->subject($subscriberId);
        if ($subscriber === null) {
            return;
        }

        $subscriber->clearMeterReport();
        $this->rosterCache = null;
        $this->flashMessage(__('portal.broken_cleared'));
    }

    public function dismissFlash(): void
    {
        $this->flash = null;
    }

    // ---- internals -----------------------------------------------------------

    /**
     * What the row would bill if this value were the reading.
     *
     * The screen's own arithmetic, shown while the collector types; the authoritative
     * figures are the ones BillingService writes on save. The warnings come from
     * ReadingQuality — the same rule the office's board uses on readings already billed, so
     * "a jump" means one thing in this app. Both are non-blocking: the only value refused
     * below is one that is lower than the last reading, which cannot be measured.
     *
     * @param  array<string, mixed>  $row
     * @return array{consumption: float, usage: float, total: float, due: float, warning: ?string, average: ?float}
     */
    private function preview(array $row, float $value, float $price): array
    {
        $consumption = round($value - (float) $row['previous'], 2);
        $usage = round($consumption * $price, 2);
        $total = round($usage + (float) $row['maintenance'], 2);
        $warning = null;
        $average = null;

        if ($consumption < 0) {
            // Not a quality verdict but an impossible value: nothing was measured, and the
            // save below will refuse it. Said here so the collector never walks away from a
            // house carrying a reading that cannot be recorded.
            $warning = 'lower';
        } else {
            $warning = ReadingQuality::verdict($row['subscriber'], $this->period, $consumption);

            if ($warning === ReadingQuality::JUMP) {
                $average = ReadingQuality::average($row['subscriber'], $this->period);
            }
        }

        return [
            'consumption' => $consumption,
            'usage' => $usage,
            'total' => $total,
            'due' => round($total + (float) $row['debt'], 2),
            'warning' => $warning,
            'average' => $average,
        ];
    }

    /** The typed value of a row, or null when the field is empty or unusable. */
    private function typed(int $subscriberId): ?float
    {
        $raw = trim((string) ($this->values[$subscriberId] ?? ''));

        return $raw === '' || ! is_numeric($raw) ? null : (float) $raw;
    }

    private function subject(int $subscriberId): ?Subscriber
    {
        foreach ($this->roster()['rows'] as $row) {
            if ((int) $row['id'] === $subscriberId) {
                /** @var Subscriber $subscriber */
                $subscriber = $row['subscriber'];

                return $subscriber;
            }
        }

        return null;
    }

    protected function flashMessage(string $message, string $type = 'success'): void
    {
        $this->flash = $message;
        $this->flashType = $type;
    }

    public function render()
    {
        $roster = $this->roster();

        return view('livewire.route-reading-sheet', [
            'rows' => $this->rows(),
            'progress' => $this->progress(),
            'typed' => $this->typedCount(),
            'typedTotal' => $this->typedTotal(),
            'flat' => $roster['flat'],
            'inactive' => $roster['inactive'],
            'price' => $this->price(),
        ]);
    }
}
