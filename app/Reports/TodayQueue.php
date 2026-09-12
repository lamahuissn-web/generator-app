<?php

namespace App\Reports;

use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CollectionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The office's day, as a worklist: what is unfinished RIGHT NOW, in the order to answer it.
 *
 * The dashboard answers "ما صار" — how much was invoiced, what came in, what is owed. This
 * answers the other question, the one the operator actually starts the morning with: شو ناقص,
 * ومين بدو يروح يعمل شو. Every row here is an unfinished obligation with a screen that closes
 * it, and the screen is meant to EMPTY: a row disappears when there is nothing left to do about
 * it. That is the whole point — an office that has to remember what it was in the middle of,
 * spread over eight screens, forgets; a screen that says "6 بنود ناقصة" and then "0" does not.
 *
 * Three rules keep it from turning into a second dashboard, which is what a worklist becomes
 * when nobody is careful:
 *
 *  - A ROW THAT IS ALWAYS THERE IS A ROW NOBODY READS. Nothing here is a figure to admire:
 *    readings are counted only while they are missing, meters only while a case is open, cash
 *    only while somebody is holding it. When the answer is zero the row moves to the "done"
 *    section rather than staying on top with a nought in it.
 *  - WORK IN PROGRESS IS NOT WORK WAITING. The billing row appears when the cycle can be run —
 *    every reading in — not when the first reading lands, because a row that shows up on the
 *    3rd and stays until the 30th teaches the operator to scroll past it. Late in the month it
 *    is the one thing they need to see; on the 3rd it would be noise.
 *  - AGING IS THE POINT. "3 محصّلين عندهم كاش" is a fact; "سامر عندو $2,100 من 5 أيام" is a
 *    decision. Wherever the data knows how long something has been waiting, the row says so.
 *
 * Everything is read, never written, and every figure comes from the service or report that
 * already owns that meaning (readingProgress, MeterCaseBoard, CollectionService, OverdueReport):
 * this screen composes the panel's own answers instead of growing a second copy of the rules
 * that could disagree with the screens the operator goes on to open.
 */
final class TodayQueue
{
    public const TONE_DANGER = 'danger';

    public const TONE_WARNING = 'warning';

    public const TONE_INFO = 'info';

    public const TONE_MUTED = 'muted';

    /**
     * Days of silence in the expense book before it is worth a row.
     *
     * Two days of nothing is a weekend, and a row that fires every weekend is a row that gets
     * ignored on the week it matters.
     */
    public const EXPENSE_GAP_DAYS = 3;

    /** Collectors named individually in the cash row before the rest is summarised. */
    public const CASH_NAMED = 3;

    private ?Collection $itemsCache = null;

    private ?Collection $doneCache = null;

    private ?array $progressCache = null;

    private ?Collection $cashCache = null;

    private ?array $overdueCache = null;

    private ?array $todayCache = null;

    public function __construct(private readonly string $period) {}

    public static function make(?string $period = null): self
    {
        $period ??= BillingService::currentPeriod();

        return new self(
            preg_match(BillingService::PERIOD_REGEX, $period) === 1 ? $period : BillingService::currentPeriod()
        );
    }

    /** The cycle this queue is about ('Y-m'). */
    public function period(): string
    {
        return $this->period;
    }

    /** How serious a tone is, for the sort: lower is more urgent. */
    public static function rank(string $tone): int
    {
        return match ($tone) {
            self::TONE_DANGER => 0,
            self::TONE_WARNING => 1,
            self::TONE_INFO => 2,
            default => 3,
        };
    }

    /**
     * What still needs doing, most urgent first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function items(): Collection
    {
        return $this->itemsCache ??= $this->all()
            ->reject(fn (array $item): bool => $item['done'])
            ->sortBy(fn (array $item): int => self::rank((string) ($item['tone'] ?? self::TONE_MUTED)))
            ->values();
    }

    /**
     * What is already answered — and stays on the page to say so.
     *
     * Not a scoreboard: it is the receipt for the morning. An office that finished the readings
     * should be able to SEE that it finished them, otherwise the absence of a row is
     * indistinguishable from having scrolled past it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function done(): Collection
    {
        return $this->doneCache ??= $this->all()
            ->filter(fn (array $item): bool => $item['done'])
            ->values();
    }

    /**
     * The four figures across the top: not what is pending (the cards below say that) but what
     * TODAY has already brought in — cash taken, readings walked, the money still out there.
     *
     * @return array<int, array<string, mixed>>
     */
    public function strip(): array
    {
        $today = $this->today();
        $progress = $this->progress();
        $holders = $this->cash();
        $overdue = $this->overdue();

        return [
            [
                'label' => __('today.strip_cash'),
                'value' => money($today['amount']),
                'hint' => plural('today.strip_payments', $today['count']),
                'icon' => 'ki-wallet',
                'tone' => 'success',
            ],
            [
                'label' => __('today.strip_in_hand'),
                'value' => money((float) $holders->sum('in_hand')),
                'hint' => plural('today.strip_holders', $holders->count()),
                'icon' => 'ki-bank',
                'tone' => $holders->isEmpty() ? 'success' : 'warning',
            ],
            [
                'label' => __('today.strip_readings'),
                'value' => number_format($today['readings']),
                'hint' => __('today.strip_readings_hint', ['percent' => ltr_isolate($progress['percent'].'%')]),
                'icon' => 'ki-notepad-edit',
                'tone' => 'info',
            ],
            [
                'label' => __('today.strip_debt'),
                'value' => money($overdue['total']),
                'hint' => plural('today.strip_debt_hint', $overdue['count']),
                'icon' => 'ki-information-2',
                'tone' => $overdue['count'] === 0 ? 'success' : ($overdue['red'] > 0 ? 'danger' : 'warning'),
            ],
        ];
    }

    /**
     * What today has brought in so far: cash taken and readings walked.
     *
     * The only part of this screen that reads TODAY rather than the cycle, which is why it is
     * its own method — the boundary between "the day" and "the month's work" is visible in the
     * code instead of living in the reader's head.
     *
     * @return array{amount: float, count: int, readings: int}
     */
    private function today(): array
    {
        if ($this->todayCache !== null) {
            return $this->todayCache;
        }

        $payments = Payment::query()->whereDate('created_at', now()->toDateString());

        return $this->todayCache = [
            'amount' => (float) (clone $payments)->sum('amount'),
            'count' => (clone $payments)->count(),
            'readings' => Reading::query()->whereDate('created_at', now()->toDateString())->count(),
        ];
    }

    /**
     * Every concern, each answering for itself and saying whether it is finished.
     *
     * One description per concern, not two: the row an operator sees on top and the line they
     * see under "خلّص" are the same entry read twice, so a concern can never be listed as both
     * pending and done. A builder may also answer null — "nothing to say about this today" —
     * which is how a concern stays off both lists while it is merely unfinished.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function all(): Collection
    {
        return collect([
            $this->readings(),
            $this->meters(),
            $this->cashRow(),
            $this->bills(),
            $this->expenses(),
            $this->records(),
            $this->overdueRow(),
        ])->filter()->values();
    }

    // ---- the rows -------------------------------------------------------------

    /**
     * Readings still missing from the cycle.
     *
     * The count and the per-area split are BillingService's own figures — the same call the
     * billing run gates itself on — so a board that says "everything is read" and a run that
     * refuses to commit cannot exist at the same time.
     */
    private function readings(): array
    {
        $progress = $this->progress();

        if ($progress['pending'] === 0) {
            return [
                'key' => 'readings',
                'done' => true,
                'icon' => 'ki-notepad-edit',
                'title' => plural('today.readings_done', $progress['total']),
                'detail' => [],
                'route' => 'admin.readings-board',
                'cta' => __('today.readings_cta'),
            ];
        }

        $detail = [];

        // The areas with something left to walk, worst first — the collector's morning plan.
        foreach (array_slice(array_filter($progress['routes'], fn (array $row): bool => $row['pending'] > 0), 0, 2) as $route) {
            $detail[] = __('today.readings_route', [
                'route' => $route['name'],
                'readings' => plural('rdgb.readings_count', (int) $route['pending']),
            ]);
        }

        $last = $this->lastReadingAt();
        $detail[] = $last === null
            ? __('today.readings_none_yet')
            : __('today.readings_last', ['time' => ltr_isolate($this->momentLabel($last))]);

        return [
            'key' => 'readings',
            'done' => false,
            'tone' => self::TONE_DANGER,
            'icon' => 'ki-notepad-edit',
            'title' => plural('today.readings_title', (int) $progress['pending']),
            'detail' => $detail,
            'route' => 'admin.readings-board',
            'cta' => __('today.readings_cta'),
        ];
    }

    /**
     * Dead meters the field reported and the office has not answered.
     *
     * A case outlives the month it was opened in, so this row is about the oldest one rather
     * than the newest: the question is not "how many" but "how long has this house been
     * unbillable", and MeterCaseBoard already knows.
     */
    private function meters(): array
    {
        $summary = MeterCaseBoard::make()->summary();

        if ($summary['total'] === 0) {
            return [
                'key' => 'meters',
                'done' => true,
                'icon' => 'ki-shield-cross',
                'title' => __('today.meters_done'),
                'detail' => [],
                'route' => 'admin.meter-cases',
                'cta' => __('today.meters_cta'),
            ];
        }

        $detail = [plural('today.meters_decision', (int) $summary['decision'])];

        if ($summary['oldest'] !== null) {
            $detail[] = __('today.meters_oldest', [
                'months' => plural('today.meters_oldest_months', (int) $summary['oldest']),
            ]);
        }

        return [
            'key' => 'meters',
            'done' => false,
            // A case that has spent its one estimated cycle is a decision somebody has to sign;
            // a newly reported one is a visit. Same screen, different urgency.
            'tone' => $summary['decision'] > 0 ? self::TONE_DANGER : self::TONE_WARNING,
            'icon' => 'ki-shield-cross',
            'title' => plural('today.meters_title', (int) $summary['total']),
            'detail' => $detail,
            'route' => 'admin.meter-cases',
            'cta' => __('today.meters_cta'),
        ];
    }

    /**
     * Cash still in a collector's pocket, aged by the last handover they made.
     *
     * The figure is all-time (CollectionService::inHand), not this month's: a collector who
     * collected in July and never handed it over is holding July's money today, and a monthly
     * window would hide exactly that. The age is deliberately the LAST HANDOVER rather than a
     * FIFO dating of the money — the panel cannot know which banknote settled which bill, and a
     * number invented to look precise is worse than the honest "ما سلّم ولا مرة".
     */
    private function cashRow(): array
    {
        $holders = $this->cash();

        if ($holders->isEmpty()) {
            return [
                'key' => 'cash',
                'done' => true,
                'icon' => 'ki-bank',
                'title' => __('today.cash_done'),
                'detail' => [],
                'route' => 'admin.handovers.index',
                'cta' => __('today.cash_cta'),
            ];
        }

        $detail = $holders->take(self::CASH_NAMED)->map(fn (array $row): string => __('today.cash_line', [
            'name' => $row['name'],
            'amount' => money_ltr($row['in_hand']),
            'age' => $row['last_handover'] === null
                ? __('today.cash_never')
                : plural('today.cash_days', $row['last_handover']->diffInDays(now()->startOfDay())),
        ]))->all();

        if ($holders->count() > self::CASH_NAMED) {
            $detail[] = plural('today.cash_more', $holders->count() - self::CASH_NAMED);
        }

        return [
            'key' => 'cash',
            'done' => false,
            'tone' => self::TONE_WARNING,
            'icon' => 'ki-bank',
            'title' => plural('today.cash_title', $holders->count(), [
                'amount' => money_ltr((float) $holders->sum('in_hand')),
            ]),
            'detail' => $detail,
            'route' => 'admin.handovers.index',
            'cta' => __('today.cash_cta'),
        ];
    }

    /**
     * Subscribers who can be invoiced for this cycle and have not been.
     *
     * Gated on the cycle being READY (see the class docblock): while readings are still coming
     * in, houses that could technically be billed this minute are work in progress, not work
     * waiting on the operator.
     *
     * Three states, because two would force a lie. Something to bill and the cycle complete →
     * the row. Nothing to bill at all → finished. Nothing to bill YET (every billable house is
     * simply unread) → no row in either list: not pending, and certainly not done.
     */
    private function bills(): ?array
    {
        $subscribers = Subscriber::query()->active()->get(['id', 'type']);
        $billed = Bill::query()->where('period', $this->period)->pluck('subscriber_id')->flip();
        $read = Reading::query()->where('period', $this->period)->pluck('subscriber_id')->flip();

        $metered = 0;
        $flat = 0;
        $waiting = 0;

        foreach ($subscribers as $subscriber) {
            if ($billed->has($subscriber->id)) {
                continue;
            }

            if ($subscriber->type === Subscriber::TYPE_FLAT) {
                $flat++;
            } elseif ($read->has($subscriber->id)) {
                $metered++;
            } else {
                $waiting++;
            }
        }

        $ready = $metered + $flat;

        if ($ready === 0) {
            // Every house without a bill is one nobody has read yet, so the cycle is not being
            // invoiced short — it has simply not been invoiced yet, and calling that "done" here
            // is a lie the operator has no way to see through.
            return $waiting === 0 ? [
                'key' => 'bills',
                'done' => true,
                'icon' => 'ki-receipt-square',
                'title' => __('today.bills_done'),
                'detail' => [],
                'route' => 'admin.generate-bills',
                'cta' => __('today.bills_cta'),
            ] : null;
        }

        // Readings still arriving: houses that could be billed this minute are work in progress,
        // not work waiting on the operator, and this row is silent until the cycle can be run.
        if ($this->progress()['pending'] > 0) {
            return null;
        }

        return [
            'key' => 'bills',
            'done' => false,
            'tone' => self::TONE_WARNING,
            'icon' => 'ki-receipt-square',
            'title' => plural('today.bills_title', $ready),
            'detail' => [__('today.bills_split', [
                'metered' => plural('today.bills_metered', $metered),
                'flat' => plural('today.bills_flat', $flat),
            ])],
            'route' => 'admin.generate-bills',
            'cta' => __('today.bills_cta'),
        ];
    }

    /**
     * Days gone by in the month with nothing written in the expense book.
     *
     * Counted as silence since the last entry rather than "days with no row": fuel is bought in
     * batches and a day with no purchase is not an omission. What matters is whether the book
     * has stopped moving, because a month of expenses reconstructed from memory in October is
     * a month of expenses that is wrong.
     */
    private function expenses(): array
    {
        // Bound as moments, not as 'Y-m-d' strings: a date-cast column is STORED as
        // '2026-09-12 00:00:00', which sorts ABOVE the plain '2026-09-12' an upper string bound
        // compares against — an entry written today would fall outside its own month.
        $rows = Expense::query()->whereBetween('expense_date', [
            now()->startOfMonth()->startOfDay(),
            now()->endOfDay(),
        ]);

        $count = (clone $rows)->count();
        $total = (float) (clone $rows)->sum('amount');
        $last = (clone $rows)->max('expense_date');

        // With no entry at all this month, the silence started on the 1st.
        $lastDate = $last === null ? now()->startOfMonth() : Carbon::parse($last);
        $silent = (int) $lastDate->startOfDay()->diffInDays(now()->startOfDay());

        if ($silent < self::EXPENSE_GAP_DAYS) {
            return [
                'key' => 'expenses',
                'done' => true,
                'icon' => 'ki-chart-line-down',
                'title' => __('today.expenses_done'),
                'detail' => [$this->expenseLine($last, $total)],
                'route' => 'admin.expenses.index',
                'cta' => __('today.expenses_cta'),
            ];
        }

        return [
            'key' => 'expenses',
            'done' => false,
            'tone' => self::TONE_MUTED,
            'icon' => 'ki-chart-line-down',
            'title' => plural('today.expenses_gap', $silent),
            'detail' => [$this->expenseLine($last, $total)],
            'route' => 'admin.expenses.index',
            'cta' => __('today.expenses_cta'),
        ];
    }

    /**
     * A house that no collector walks and a house that cannot be called.
     *
     * Both are quiet faults: the app bills a subscriber with no area exactly as well as any
     * other, so nothing breaks and nobody notices until the money does not arrive. This is the
     * one row that is not about a date — it is about the network being complete enough for the
     * other six rows to be true.
     */
    private function records(): array
    {
        $active = Subscriber::query()->active();

        $noRoute = (clone $active)->whereNull('route_id')->count();
        $noPhone = (clone $active)->where(fn ($query) => $query->whereNull('phone')->orWhere('phone', ''))->count();
        $total = (clone $active)->where(function ($query): void {
            $query->whereNull('route_id')->orWhere(fn ($inner) => $inner->whereNull('phone')->orWhere('phone', ''));
        })->count();

        if ($total === 0) {
            return [
                'key' => 'records',
                'done' => true,
                'icon' => 'ki-map',
                'title' => __('today.records_done'),
                'detail' => [],
                'route' => 'admin.subscribers.index',
                'cta' => __('today.records_cta'),
            ];
        }

        $detail = [];
        if ($noRoute > 0) {
            $detail[] = plural('today.records_no_route', $noRoute);
        }
        if ($noPhone > 0) {
            $detail[] = plural('today.records_no_phone', $noPhone);
        }

        return [
            'key' => 'records',
            'done' => false,
            'tone' => self::TONE_INFO,
            'icon' => 'ki-map',
            'title' => plural('today.records_title', $total),
            'detail' => $detail,
            'route' => 'admin.subscribers.index',
            'cta' => __('today.records_cta'),
        ];
    }

    /**
     * What the network owes, aged.
     *
     * The one row that is never going to be zero in a real network, which is exactly why it
     * sits below the day's obligations instead of at the top: it is a standing condition to
     * work, not a task to close this morning. It is here at all because "لا متأخرين" is worth
     * seeing on the day it is true.
     */
    private function overdueRow(): array
    {
        $summary = $this->overdue();

        if ($summary['count'] === 0) {
            return [
                'key' => 'overdue',
                'done' => true,
                'icon' => 'ki-information-2',
                'title' => __('today.overdue_done'),
                'detail' => [],
                'route' => 'admin.overdue-report',
                'cta' => __('today.overdue_cta'),
            ];
        }

        return [
            'key' => 'overdue',
            'done' => false,
            'tone' => self::TONE_INFO,
            'icon' => 'ki-information-2',
            'title' => plural('today.overdue_title', (int) $summary['count']),
            'detail' => [__('today.overdue_red', ['amount' => money_ltr($summary['red'])])],
            'route' => 'admin.overdue-report',
            'cta' => __('today.overdue_cta'),
        ];
    }

    // ---- the figures behind the rows ------------------------------------------

    /** @return array{total: int, recorded: int, pending: int, percent: int, routes: array<int, array<string, mixed>>} */
    private function progress(): array
    {
        return $this->progressCache ??= BillingService::readingProgress($this->period);
    }

    /** Collectors holding cash right now, largest first. Empty when the drawer is square. */
    private function cash(): Collection
    {
        if ($this->cashCache !== null) {
            return $this->cashCache;
        }

        $collectors = User::query()->where('role', User::ROLE_COLLECTOR)->orderBy('name')->get(['id', 'name']);

        if ($collectors->isEmpty()) {
            return $this->cashCache = collect();
        }

        $handovers = CashHandover::query()
            ->whereIn('collector_id', $collectors->pluck('id')->all())
            ->selectRaw('collector_id, MAX(handover_date) as last')
            ->groupBy('collector_id')
            ->pluck('last', 'collector_id');

        return $this->cashCache = $collectors
            ->map(function (User $collector) use ($handovers): array {
                $last = $handovers->get($collector->id);

                return [
                    'id' => $collector->id,
                    'name' => $collector->name,
                    'in_hand' => CollectionService::inHand($collector->id),
                    'last_handover' => $last === null ? null : Carbon::parse($last)->startOfDay(),
                ];
            })
            ->filter(fn (array $row): bool => $row['in_hand'] > 0)
            ->sortByDesc('in_hand')
            ->values();
    }

    /** @return array{count: int, red: float, yellow: float, total: float} */
    private function overdue(): array
    {
        return $this->overdueCache ??= OverdueReport::make()->summary();
    }

    /** When a reading for this cycle was last touched, or null when none has arrived. */
    private function lastReadingAt(): ?Carbon
    {
        $at = Reading::query()->where('period', $this->period)->max('updated_at');

        return $at === null ? null : Carbon::parse($at);
    }

    /** A moment as the office reads it: the clock today, the date otherwise. */
    private function momentLabel(Carbon $moment): string
    {
        return $moment->isToday() ? $moment->format('H:i') : $moment->format('Y-m-d');
    }

    /** The expense book in one line: where it stopped, and what the month adds up to. */
    private function expenseLine(?string $last, float $total): string
    {
        return __('today.expenses_line', [
            'last' => $last === null ? __('today.expenses_none') : ltr_isolate(Carbon::parse($last)->format('Y-m-d')),
            'amount' => money_ltr($total),
        ]);
    }
}
