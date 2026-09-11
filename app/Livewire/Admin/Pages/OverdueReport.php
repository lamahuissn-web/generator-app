<?php

namespace App\Livewire\Admin\Pages;

use App\Livewire\Admin\Concerns\HasFilters;
use App\Models\Route;
use App\Reports\OverdueReport as Report;
use Livewire\Component;

/**
 * Who owes what, aged.
 *
 * The bucket rule and the aggregation live in App\Reports\OverdueReport; this screen only
 * holds the filters and the CSV button.
 */
class OverdueReport extends Component
{
    use HasFilters;

    public static function subheading(): string
    {
        return __('over.sub');
    }

    public function mount(): void
    {
        $this->mountFilters();
    }

    public function filterSchema(): array
    {
        return [
            [
                'key' => 'bucket',
                'label' => __('over.age'),
                'type' => 'select',
                'default' => 'all',
                'options' => Report::bucketOptions(),
                'rules' => ['required'],
            ],
            [
                'key' => 'route_id',
                'label' => __('over.route'),
                'type' => 'select',
                'options_from' => fn (): array => Route::query()->orderBy('name')->pluck('name', 'id')->all(),
                'rules' => ['nullable', 'integer', 'exists:routes,id'],
            ],
        ];
    }

    protected function report(): Report
    {
        $routeId = $this->filter('route_id');

        return Report::make(
            (string) ($this->filter('bucket') ?: 'all'),
            $routeId === null ? null : (int) $routeId,
        );
    }

    public function export()
    {
        return response()->streamDownload(
            fn () => print $this->report()->csv(),
            'overdue-'.now()->format('Y-m-d').'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    public function render()
    {
        $report = $this->report();

        return view('livewire.admin.pages.overdue-report', [
            'rows' => $report->rows(),
            'summary' => $report->summary(),
        ]);
    }
}
