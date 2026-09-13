<?php

namespace App\Livewire\Admin\Pages;

use App\Livewire\Admin\Concerns\HasFilters;
use App\Models\CashHandover;
use App\Models\User;
use App\Services\CashHandoverService;
use App\Services\CollectionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * التحصيل والتسليم — the office's cash on one screen.
 *
 * Three screens used to answer three quarters of one question: the handover register («who handed
 * what»), the collector's statement (his ledger) and the collection report (what came in over a
 * period). They disagreed exactly where it hurt — the same handover could be recorded from two of
 * them, and «بيده الآن» was computed and captioned twice — so they are one screen now, built around
 * the COLLECTOR rather than around the old pages: one row answers «من مين بدنا نستلم؟», and the row
 * under it is his whole file.
 *
 * The figures carry their scope in words (بالفترة / كل الوقت) on purpose. That is not decoration:
 * the table deliberately mixes the period's collections with the all-time cash position, and an
 * unlabelled mix is added up by whoever reads it and comes back as an arithmetic complaint.
 *
 * No domain rule moved here. CollectionService computes the money, CashHandoverService guards it (a
 * collector can never hand over more than he holds, edits included) and this component arranges.
 */
class Collections extends Component
{
    use HasFilters;

    public static function subheading(): string
    {
        return __('col.hub_sub');
    }

    /** The dossier's tabs. */
    public const TABS = ['ledger', 'audit', 'handovers', 'log'];

    /** What سجل التدقيق shows. */
    public const LOG_KINDS = ['all', 'payment', 'handover', 'edits'];

    /** The period chips. */
    public const PRESETS = ['this_month', 'last_month', 'last_7', 'all_time'];

    /**
     * «كل الوقت» as a range.
     *
     * The report queries are range queries by nature, so all-time is expressed as a range wide
     * enough to contain every row rather than as a second code path through the same queries — one
     * description of the arithmetic, and no branch that can disagree with the other.
     */
    public const ALL_TIME_FROM = '2000-01-01';

    /** Whose file is open under the table. */
    public ?int $selectedId = null;

    /** ledger | audit | handovers | log */
    public string $tab = 'ledger';

    /** The running balance is ALL-TIME; the operator may cut it to the period on purpose. */
    public bool $cutToPeriod = false;

    /** تدقيق التحصيل narrows inside the collector already selected. */
    public string $auditSearch = '';

    public ?string $auditMethod = null;

    /** سجل التدقيق. */
    public string $logKind = 'all';

    /** Which period chip is lit; null once the dates are typed by hand. */
    public ?string $preset = 'this_month';

    /** The handover modal: open + no id = a new record, open + id = editing that record. */
    public bool $handoverOpen = false;

    public ?int $handoverId = null;

    /** @var array<string, mixed> */
    public array $handover = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ];

        // Deep links. The two screens being replaced carried the collector, the range and the
        // all-time flag in the query string, and the dashboard and the collector portal link in.
        foreach (['from', 'to'] as $key) {
            $value = request()->input($key);

            if (is_string($value) && $value !== '') {
                $this->filters[$key] = $value;
                $this->preset = null;
            }
        }

        if (request()->filled('collector_id')) {
            $this->selectedId = (int) request()->integer('collector_id');
        }

        $tab = (string) request()->input('tab', '');

        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }

        if (request()->boolean('all_time')) {
            $this->setPreset('all_time');
        }
    }

    public function filterSchema(): array
    {
        return [
            [
                'key' => 'from',
                'label' => __('col.from'),
                'type' => 'date',
                'default' => now()->startOfMonth()->toDateString(),
                'rules' => ['required', 'date'],
            ],
            [
                'key' => 'to',
                'label' => __('col.to'),
                'type' => 'date',
                'default' => now()->toDateString(),
                'rules' => ['required', 'date', 'after_or_equal:from'],
            ],
        ];
    }

    /**
     * Typing a date by hand takes the range out of the chips' hands.
     *
     * The chips and the two fields describe the same thing, and leaving a chip lit while the range
     * says something else is the kind of contradiction the office reads as a bug.
     */
    public function updatedFilters(): void
    {
        $this->preset = null;
        $this->validateFilters();
    }

    // ---- period ------------------------------------------------------------

    public function setPreset(string $preset): void
    {
        $this->preset = in_array($preset, self::PRESETS, true) ? $preset : null;

        [$from, $to] = match ($this->preset) {
            'this_month' => [now()->startOfMonth(), now()->endOfDay()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_7' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'all_time' => [Carbon::parse(self::ALL_TIME_FROM), now()->endOfDay()],
            default => [null, null],
        };

        if ($from !== null && $to !== null) {
            $this->filters['from'] = $from->toDateString();
            $this->filters['to'] = $to->toDateString();
        }

        $this->resetErrorBag();
    }

    public function isAllTime(): bool
    {
        return $this->preset === 'all_time';
    }

    /** Defensive on purpose: these run during RENDER, before validation can speak. */
    public function from(): Carbon
    {
        return $this->date($this->filter('from'), Carbon::parse(self::ALL_TIME_FROM))->startOfDay();
    }

    public function to(): Carbon
    {
        // A reversed range is clamped rather than rejected — typing the second date first must not
        // leave the operator staring at an empty screen.
        $to = $this->date($this->filter('to'), now())->endOfDay();

        return $to->lt($this->from()) ? $this->from()->copy()->endOfDay() : $to;
    }

    private function date(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public function periodLabel(): string
    {
        return $this->isAllTime()
            ? __('col.st_period_all')
            : $this->from()->format('Y-m-d').' — '.$this->to()->format('Y-m-d');
    }

    /**
     * The range alone, for the period report's print.
     *
     * Deliberately WITHOUT the selected collector: that button prints the office's period, and only
     * the collector's own statement is about one man.
     */
    public function reportPrintParams(): array
    {
        return array_filter([
            'from' => $this->from()->toDateString(),
            'to' => $this->to()->toDateString(),
            'all_time' => $this->isAllTime() ? 1 : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** The range as URL params, for the print links and the collector portal's back-links. */
    public function exportParams(): array
    {
        return array_filter([
            'from' => $this->from()->toDateString(),
            'to' => $this->to()->toDateString(),
            'collector_id' => $this->selectedRow()['collector_id'] ?? null,
            'all_time' => $this->isAllTime() ? 1 : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    // ---- selection ---------------------------------------------------------

    public function selectCollector(int $id): void
    {
        $this->selectedId = $id;
        $this->resetErrorBag();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    public function toggleCut(): void
    {
        $this->cutToPeriod = ! $this->cutToPeriod;
    }

    // ---- data --------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    public function collectors(): Collection
    {
        $last = CollectionService::lastHandoverByCollector();

        return CollectionService::byCollector($this->from(), $this->to())
            ->map(function (array $row) use ($last) {
                $handover = $last[$row['collector_id']] ?? null;
                $row['last_handover'] = $handover['date'] ?? null;
                $row['last_handover_amount'] = $handover['amount'] ?? null;
                $row['since_days'] = $handover === null
                    ? null
                    : (int) Carbon::parse($handover['date'])->diffInDays(now()->startOfDay());

                return $row;
            });
    }

    /** The collector whose file is open — the top row until the operator picks another. */
    public function selectedRow(): ?array
    {
        $rows = $this->collectors();

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->firstWhere('collector_id', $this->selectedId) ?? $rows->first();
    }

    public function collector(): ?User
    {
        $id = $this->selectedRow()['collector_id'] ?? null;

        return $id ? User::find($id) : null;
    }

    /** Cash the selected collector is holding right now (all-time — see the scope rule above). */
    public function inHand(): float
    {
        $collector = $this->collector();

        return $collector ? CollectionService::inHand($collector->id) : 0.0;
    }

    public function totals(): array
    {
        return CollectionService::totals($this->from(), $this->to());
    }

    /** @return Collection<int, array<string, mixed>> */
    public function routeRows(): Collection
    {
        return CollectionService::byRoute($this->from(), $this->to());
    }

    /** @return Collection<int, array<string, mixed>> */
    public function dayRows(): Collection
    {
        return CollectionService::byDay($this->from(), $this->to());
    }

    public function ledger(): ?array
    {
        $collector = $this->collector();

        // All-time unless the operator asks for the period: the closing balance has to be the cash
        // actually in hand, and the cut view carries its opening balance as its first row.
        return $collector ? CollectionService::collectorLedger($collector, $this->cutToPeriod ? $this->from() : null) : null;
    }

    /** @return array{days: array<int, array<string, mixed>>, rows_total: int, total: float} */
    public function documents(): array
    {
        $collector = $this->collector();

        return $collector
            ? CollectionService::collectorDocuments($collector, $this->from(), $this->to(), $this->auditMethod, $this->auditSearch)
            : ['days' => [], 'rows_total' => 0, 'total' => 0.0];
    }

    /** @return array{rows: array<int, array<string, mixed>>, closing: float, rows_total: int, recorded: int, unrecorded: int} */
    public function trail(): array
    {
        $collector = $this->collector();

        if (! $collector) {
            return ['rows' => [], 'closing' => 0.0, 'rows_total' => 0, 'recorded' => 0, 'unrecorded' => 0];
        }

        $trail = CollectionService::collectorAuditTrail($collector);

        $kinds = match ($this->logKind) {
            'payment' => ['payment'],
            'handover' => ['handover'],
            'edits' => ['void', 'edit', 'delete'],
            default => [],
        };

        if ($kinds !== []) {
            $trail['rows'] = array_values(array_filter(
                $trail['rows'],
                fn (array $row) => in_array($row['kind'], $kinds, true),
            ));
        }

        return $trail;
    }

    public function availableToHandOver(): float
    {
        $collector = $this->collector();

        if ($collector === null) {
            return 0.0;
        }

        // The record being edited is already counted in the in-hand figure, so the service adds it
        // back — an edit has to be able to raise a settlement back to the full amount.
        return CashHandoverService::availableFor($collector->id, $this->handoverId ? CashHandover::find($this->handoverId) : null);
    }

    /** @return Collection<int, CashHandover> */
    public function handovers(): Collection
    {
        $collector = $this->collector();

        return $collector
            ? CashHandover::query()->where('collector_id', $collector->id)
                ->with('recordedBy:id,name')
                ->orderByDesc('handover_date')
                ->orderByDesc('id')
                ->get()
            : collect();
    }

    // ---- the handover modal ------------------------------------------------

    public function openHandover(?int $id = null): void
    {
        $collector = $this->collector();
        abort_if($collector === null, 404);

        $record = $id
            ? CashHandover::query()->where('collector_id', $collector->id)->findOrFail($id)
            : null;

        $this->handoverId = $record?->id;

        // A new settlement starts from everything he holds: that is what the operator came to clear,
        // and it is the amount they are least likely to have to correct.
        $this->handover = [
            'amount' => $record ? (float) $record->amount : CashHandoverService::availableFor($collector->id),
            'handover_date' => $record?->handover_date?->toDateString() ?? now()->toDateString(),
            'note' => $record?->note,
        ];

        $this->resetErrorBag();
        $this->handoverOpen = true;
    }

    public function closeHandover(): void
    {
        $this->handoverOpen = false;
        $this->handoverId = null;
        $this->resetErrorBag();
    }

    public function saveHandover(): void
    {
        $collector = $this->collector();
        abort_if($collector === null, 404);

        $this->validate([
            'handover.amount' => ['required', 'numeric', 'min:0.01'],
            'handover.handover_date' => ['required', 'date'],
            'handover.note' => ['nullable', 'string', 'max:200'],
        ]);

        $data = [
            'collector_id' => $collector->id,
            'amount' => (float) $this->handover['amount'],
            'handover_date' => $this->handover['handover_date'],
            'note' => ($this->handover['note'] ?? null) ?: null,
        ];

        try {
            if ($this->handoverId) {
                CashHandoverService::update(CashHandover::findOrFail($this->handoverId), $data);
                session()->flash('status', __('col.handover_updated'));
            } else {
                CashHandoverService::create($data, auth()->id());
                session()->flash('status', __('col.handover_saved', ['amount' => money_ltr($data['amount'])]));
            }
        } catch (\RuntimeException $e) {
            // Cash he is not holding — a business rule, not a crash. The reason goes under the field
            // it belongs to and the modal stays open with the operator's typing intact.
            $this->addError('handover.amount', $e->getMessage());

            return;
        }

        $this->closeHandover();
    }

    /** Delete a settlement — from the modal (no id) or straight from its row. */
    public function deleteHandover(?int $id = null): void
    {
        $id ??= $this->handoverId;

        if ($id) {
            $record = CashHandover::find($id);

            if ($record) {
                // Deleting gives the amount back to his hand, and the audit trail keeps the record of
                // both the settlement and its deletion.
                CashHandoverService::delete($record);
                session()->flash('status', __('col.handover_deleted'));
            }
        }

        $this->closeHandover();
    }

    /** The collector's own audit, as a spreadsheet: what he took, from whom, when, against what. */
    public function exportAudit()
    {
        $collector = $this->collector();
        abort_if($collector === null, 404);

        $documents = $this->documents();
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [__('col.audit_title_export', ['name' => $collector->name]), $this->periodLabel()]);
        fputcsv($handle, [
            __('col.audit_time'), __('col.audit_receipt'), __('col.payer'), __('t.route'),
            __('t.amount'), __('col.method'), __('col.for_invoice'), __('col.collector_note'), __('col.recorded_by'),
        ]);

        foreach ($documents['days'] as $day) {
            fputcsv($handle, [__('col.audit_day', [
                'date' => $day['day'],
                'count' => $day['count'],
                'total' => number_format($day['total'], 2, '.', ''),
            ])]);

            foreach ($day['rows'] as $row) {
                fputcsv($handle, [
                    $row['time'],
                    $row['number'],
                    $row['subscriber'].($row['meter_no'] ? ' ('.$row['meter_no'].')' : ''),
                    $row['route'],
                    number_format($row['amount'], 2, '.', ''),
                    $row['method'].($row['lira'] ? ' — '.$row['lira'] : ''),
                    implode('، ', $row['invoices']),
                    $row['note'],
                    $row['recorder_label'],
                ]);
            }
        }

        fputcsv($handle, []);
        fputcsv($handle, [__('col.collected'), number_format($documents['total'], 2, '.', '')]);

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return response()->streamDownload(
            fn () => print $contents,
            'audit-'.str($collector->name)->slug().'-'.$this->from()->toDateString().'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    /** The period's report as a spreadsheet — the cut the closed screen also offered. */
    public function exportReport()
    {
        $totals = $this->totals();
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [__('col.hub_title'), $this->from()->toDateString(), $this->to()->toDateString()]);
        fputcsv($handle, [__('col.collected'), number_format($totals['collected'], 2, '.', '')]);
        fputcsv($handle, [__('col.handed'), number_format($totals['handed'], 2, '.', '')]);
        fputcsv($handle, [__('col.in_hand_total'), number_format($totals['in_hand'], 2, '.', '')]);
        fputcsv($handle, []);

        fputcsv($handle, [__('col.by_day')]);
        fputcsv($handle, [__('t.date'), __('col.payments_count'), __('t.amount')]);
        foreach ($this->dayRows() as $row) {
            fputcsv($handle, [$row['day'], $row['payments_count'], number_format($row['collected'], 2, '.', '')]);
        }
        fputcsv($handle, []);

        fputcsv($handle, [__('col.by_route')]);
        fputcsv($handle, [__('t.route'), __('col.payments_count'), __('t.amount')]);
        foreach ($this->routeRows() as $row) {
            fputcsv($handle, [$row['name'], $row['payments_count'], number_format($row['collected'], 2, '.', '')]);
        }
        fputcsv($handle, []);

        fputcsv($handle, [__('col.collectors_title')]);
        fputcsv($handle, [__('col.collector'), __('col.payments_count'), __('col.collected'), __('col.handed'), __('col.in_hand')]);
        foreach ($this->collectors() as $row) {
            fputcsv($handle, [
                $row['name'], $row['payments_count'],
                number_format($row['collected'], 2, '.', ''),
                number_format($row['handed'], 2, '.', ''),
                number_format($row['in_hand'], 2, '.', ''),
            ]);
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return response()->streamDownload(
            fn () => print $contents,
            'collections-'.$this->from()->toDateString().'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    public function render()
    {
        return view('livewire.admin.pages.collections', [
            'collectors' => $this->collectors(),
            'selected' => $this->selectedRow(),
            'collector' => $this->collector(),
            'totals' => $this->totals(),
            'routeRows' => $this->routeRows(),
            'dayRows' => $this->dayRows(),
            'ledger' => $this->ledger(),
            'documents' => $this->documents(),
            'trail' => $this->trail(),
            'handoverRows' => $this->handovers(),
            'periodLabel' => $this->periodLabel(),
            'exportParams' => $this->exportParams(),
            'reportParams' => $this->reportPrintParams(),
            'available' => $this->availableToHandOver(),
        ]);
    }
}
