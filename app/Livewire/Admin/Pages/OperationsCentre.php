<?php

namespace App\Livewire\Admin\Pages;

use App\Livewire\Admin\Concerns\HasFilters;
use App\Reports\OperationsReport;
use App\Reports\OverdueReport;
use App\Reports\ProfitLossReport;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * مركز العمليات — six plain numbers, the rest behind one button.
 *
 * The board is six cards in the office's own words (دخل الشهر · مكتسب · علينا · متأخرات ·
 * مصاريف · صافي). Everything the owner needs to learn — losses, cost per kWh, fuel — lives
 * behind «أرقام أكثر». A card with no input shows its reason and never a fabricated number.
 */
class OperationsCentre extends Component
{
    use HasFilters;

    public bool $more = false;

    public static function subheading(): string
    {
        return __('ops.centre_sub');
    }

    public static function title(): string
    {
        return __('mdl.operations_centre');
    }

    public function mount(): void
    {
        $this->mountFilters();
    }

    public function filterSchema(): array
    {
        return [[
            'key' => 'month',
            'label' => __('t.month'),
            'type' => 'month',
            'default' => now()->format('Y-m'),
            'rules' => ['required', 'regex:/^\\d{4}-\\d{2}$/'],
        ]];
    }

    public function month(): string
    {
        $value = $this->filter('month');

        return is_string($value) && preg_match('/^\\d{4}-\\d{2}$/', $value) === 1
            ? $value
            : now()->format('Y-m');
    }

    public function toggleMore(): void
    {
        $this->more = ! $this->more;
    }

    /**
     * What is still owed — `bills − payments`.
     *
     * Reuses the existing overdue machinery rather than inventing a new definition: the same total
     * that ages red/yellow in the overdue report is what the board calls «متأخرات».
     */
    private function overdueTotal(): float
    {
        return (float) OverdueReport::make()->summary()['total'];
    }

    /**
     * Per-route energy balance for the month — not a separate report the owner has to hunt for.
     *
     * The arithmetic lives in OperationsReport::perRoute() with the rest of the engine: a surface that
     * re-derives a rule is a second definition of it, and the two drift.
     */
    private function perRoute(): Collection
    {
        return OperationsReport::forMonth($this->month())->perRoute();
    }

    public function render()
    {
        $period = $this->month();
        $board = OperationsReport::forMonth($period)->board();
        $overdue = $this->overdueTotal();

        return view('livewire.admin.pages.operations-centre', [
            'period' => $period,
            'board' => $board,
            'overdue' => $overdue,
            'perRoute' => $this->perRoute(),
            'more' => $this->more,
            'equipmentCount' => \App\Models\Equipment::query()->count(),
        ]);
    }
}
