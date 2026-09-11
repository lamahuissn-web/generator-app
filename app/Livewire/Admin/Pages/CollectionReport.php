<?php

namespace App\Livewire\Admin\Pages;

use App\Livewire\Admin\Concerns\HasFilters;
use App\Models\Route;
use App\Models\User;
use App\Services\CollectionService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * What was collected in a period, cut three ways: by day, by area and by collector.
 *
 * All figures come from CollectionService — the same service the collector portal and the
 * handover screen use, so a number here always agrees with the ledger behind it.
 */
class CollectionReport extends Component
{
    use HasFilters;

    public static function subheading(): string
    {
        return __('col.sub');
    }

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'route_id' => null,
            'collector_id' => null,
        ];

        // Deep link from the collector ledger ("back to report") and from the dashboard.
        foreach (array_keys($this->filters) as $key) {
            $value = request()->input($key);

            if ($value !== null && $value !== '') {
                $this->filters[$key] = $value;
            }
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
            [
                'key' => 'route_id',
                'label' => __('col.route'),
                'type' => 'select',
                'placeholder' => __('gen.all_routes'),
                'options_from' => fn (): array => Route::query()->orderBy('name')->pluck('name', 'id')->all(),
                'rules' => ['nullable', 'integer', 'exists:routes,id'],
            ],
            [
                'key' => 'collector_id',
                'label' => __('col.collector'),
                'type' => 'select',
                'placeholder' => __('col.all_collectors'),
                'options_from' => fn (): array => User::query()->where('role', User::ROLE_COLLECTOR)
                    ->orderBy('name')->pluck('name', 'id')->all(),
                'rules' => ['nullable', 'integer', 'exists:users,id'],
            ],
        ];
    }

    // ---- accessors ---------------------------------------------------------
    // Defensive on purpose: these run during RENDER, so a half-typed date must fall back
    // rather than throw before the validation message can be shown.

    public function from(): Carbon
    {
        return $this->date($this->filter('from'), now()->startOfMonth()->toDateString())->startOfDay();
    }

    public function to(): Carbon
    {
        // A reversed range is clamped rather than rejected: the operator typing the second
        // date first would otherwise stare at an empty report.
        $to = $this->date($this->filter('to'), now()->toDateString())->endOfDay();

        return $to->lt($this->from()) ? $this->from()->copy()->endOfDay() : $to;
    }

    public function collectorId(): ?int
    {
        $id = $this->filter('collector_id');

        return $id === null ? null : (int) $id;
    }

    public function routeId(): ?int
    {
        $id = $this->filter('route_id');

        return $id === null ? null : (int) $id;
    }

    private function date(mixed $value, string $fallback): Carbon
    {
        if (! is_string($value) || $value === '') {
            return Carbon::parse($fallback);
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return Carbon::parse($fallback);
        }
    }

    public function periodLabel(): string
    {
        return $this->from()->format('Y-m-d').' — '.$this->to()->format('Y-m-d');
    }

    /** The filters as URL params, for the ledger links and the print/PDF views. */
    public function exportParams(): array
    {
        return array_filter([
            'from' => $this->from()->toDateString(),
            'to' => $this->to()->toDateString(),
            'route_id' => $this->routeId(),
            'collector_id' => $this->collectorId(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    // ---- data --------------------------------------------------------------

    public function totals(): array
    {
        return CollectionService::totals($this->from(), $this->to(), $this->collectorId(), $this->routeId());
    }

    public function collectors(): \Illuminate\Support\Collection
    {
        return CollectionService::byCollector($this->from(), $this->to())
            ->when($this->collectorId(), fn ($rows, $id) => $rows->where('collector_id', (int) $id)->values());
    }

    public function routeRows(): \Illuminate\Support\Collection
    {
        return CollectionService::byRoute($this->from(), $this->to(), $this->collectorId());
    }

    public function dayRows(): \Illuminate\Support\Collection
    {
        return CollectionService::byDay($this->from(), $this->to(), $this->collectorId(), $this->routeId());
    }

    public function export()
    {
        $totals = $this->totals();
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [__('col.title'), $this->from()->toDateString(), $this->to()->toDateString()]);
        fputcsv($handle, [__('col.collected'), number_format($totals['collected'], 2, '.', '')]);
        fputcsv($handle, [__('col.handed'), number_format($totals['handed'], 2, '.', '')]);
        fputcsv($handle, [__('col.net'), number_format($totals['net'], 2, '.', '')]);
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

        fputcsv($handle, [__('col.by_collector')]);
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
        return view('livewire.admin.pages.collection-report', [
            'totals' => $this->totals(),
            'collectors' => $this->collectors(),
            'routeRows' => $this->routeRows(),
            'dayRows' => $this->dayRows(),
            'periodLabel' => $this->periodLabel(),
            'exportParams' => $this->exportParams(),
        ]);
    }
}
