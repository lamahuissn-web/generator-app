<?php

namespace App\Livewire\Admin\Pages;

use App\Livewire\Admin\Concerns\HasFilters;
use App\Models\Route;
use App\Models\Subscriber;
use App\Reports\MeterCaseBoard as Report;
use Livewire\Component;

/**
 * Dead meters, as a worklist of cases.
 *
 * Every dead meter the field has reported, what has been done about it, and the three decisions
 * that end it: read the working meter, replace it, or move the subscriber to a flat rate — plus the
 * logged extension for a meter that is still at the workshop.
 *
 * The decisions themselves are the existing actions (RecordReading carries the estimate and the
 * replacement, ConvertToFlat the flat rate, ExtendEstimate the exception), so this screen adds the
 * one thing that was missing: a place that says what is due, and puts the right buttons next to it.
 */
class MeterCases extends Component
{
    use HasFilters;

    public static function subheading(): string
    {
        return __('mc.sub');
    }

    public function mount(): void
    {
        $this->mountFilters();
    }

    public function filterSchema(): array
    {
        return [
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
                'default' => Report::STATE_ALL,
                'options' => Report::stateOptions(),
                'rules' => ['required', 'in:'.implode(',', array_keys(Report::stateOptions()))],
            ],
        ];
    }

    public function report(): Report
    {
        $routeId = $this->filter('route_id');
        $state = $this->filter('state');

        return Report::make(
            $routeId === null ? null : (int) $routeId,
            is_string($state) && $state !== '' ? $state : Report::STATE_ALL,
        );
    }

    public function export()
    {
        return response()->streamDownload(
            fn () => print $this->report()->csv(),
            'dead-meters-'.now()->format('Y-m-d').'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * The meter works after all (or the office handled it another way) — close the case.
     *
     * The same action the collector has in the field, kept here because the office is often the one
     * that finds out: a subscriber walks in with a photo, or the technician says it was a loose wire.
     */
    public function clearReport(int $subscriberId): void
    {
        $subscriber = Subscriber::find($subscriberId);

        if ($subscriber === null || ! $subscriber->hasBrokenMeterReport()) {
            $this->addError('clear', __('mc.clear_missing'));

            return;
        }

        $subscriber->clearMeterReport();

        // Flash + reload, exactly like an action modal: this component re-renders against the
        // already-rendered layout, so a flashed message would never reach the screen.
        session()->flash('status', __('mc.clear_ok', ['name' => $subscriber->name]));

        $this->redirect(route('admin.meter-cases'), navigate: false);
    }

    public function render()
    {
        $report = $this->report();

        return view('livewire.admin.pages.meter-cases', [
            'rows' => $report->rows(),
            'summary' => $report->summary(),
            'report' => $report,
        ]);
    }
}
