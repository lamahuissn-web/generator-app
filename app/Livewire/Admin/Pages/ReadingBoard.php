<?php

namespace App\Livewire\Admin\Pages;

use App\Livewire\Admin\Concerns\HasFilters;
use App\Models\Route;
use App\Reports\ReadingBoard as Report;
use Livewire\Component;

/**
 * The month's readings, from the office's side.
 *
 * The billing-run screen already answers "will the run cover everyone?" with a count. This
 * screen answers the two questions a count cannot: WHICH house is missing, and how long it
 * has been missing — the difference between a door nobody answered this week and a line that
 * has been drawing power unbilled since June.
 *
 * The aggregation lives in App\Reports\ReadingBoard; this screen holds the filters, the CSV
 * and the layout.
 */
class ReadingBoard extends Component
{
    use HasFilters;

    public static function subheading(): string
    {
        return __('rdgb.sub');
    }

    public function mount(): void
    {
        $this->mountFilters();
    }

    public function filterSchema(): array
    {
        return [
            [
                'key' => 'period',
                'label' => __('gen.period'),
                'type' => 'month',
                'default' => now()->format('Y-m'),
                'rules' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            ],
            [
                'key' => 'route_id',
                'label' => __('t.route'),
                'type' => 'select',
                'placeholder' => __('gen.all_routes'),
                'options_from' => fn (): array => Route::query()->orderBy('name')->pluck('name', 'id')->all(),
                'rules' => ['nullable', 'integer', 'exists:routes,id'],
            ],
            [
                'key' => 'state',
                'label' => __('t.status'),
                'type' => 'select',
                'default' => Report::STATE_ATTENTION,
                'options' => Report::stateOptions(),
                'rules' => ['required', 'in:'.implode(',', array_keys(Report::stateOptions()))],
            ],
        ];
    }

    public function period(): string
    {
        $value = $this->filter('period');

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1
            ? $value
            : now()->format('Y-m');
    }

    public function report(): Report
    {
        $routeId = $this->filter('route_id');
        $state = $this->filter('state');

        return Report::make(
            $this->period(),
            $routeId === null ? null : (int) $routeId,
            is_string($state) && $state !== '' ? $state : Report::STATE_ATTENTION,
        );
    }

    public function export()
    {
        return response()->streamDownload(
            fn () => print $this->report()->csv(),
            'readings-'.$this->period().'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    public function render()
    {
        $report = $this->report();

        return view('livewire.admin.pages.reading-board', [
            'report' => $report,
            'rows' => $report->rows(),
            'summary' => $report->summary(),
            'coverage' => $report->coverage(),
            'byCollector' => $report->byCollector(),
            'period' => $this->period(),
        ]);
    }
}
