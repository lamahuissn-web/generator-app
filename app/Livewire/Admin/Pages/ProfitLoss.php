<?php

namespace App\Livewire\Admin\Pages;

use App\Livewire\Admin\Concerns\HasFilters;
use App\Reports\ProfitLossReport;
use Livewire\Component;

/**
 * Collected vs spent, one month at a time.
 *
 * Read-only: every figure comes from ProfitLossReport, so there is no path from this screen
 * to a write. The only output is a CSV.
 */
class ProfitLoss extends Component
{
    use HasFilters;

    public static function subheading(): string
    {
        return __('pl.sub');
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
            'rules' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]];
    }

    /** Falls back rather than trusting the binding: the query runs during RENDER, so a
     *  half-typed value would throw before validation could report it. */
    public function month(): string
    {
        $value = $this->filter('month');

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1
            ? $value
            : now()->format('Y-m');
    }

    public function report(): array
    {
        return ProfitLossReport::forMonth($this->month())->data();
    }

    /** The month as CSV, for the accountant who does not get a login. */
    public function export()
    {
        $data = $this->report();

        $handle = fopen('php://temp', 'r+');
        // UTF-8 BOM: without it Excel renders every Arabic label as mojibake.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [__('pl.title'), $data['label']]);
        fputcsv($handle, []);
        fputcsv($handle, [__('pl.invoiced'), number_format($data['invoiced'], 2, '.', '')]);
        fputcsv($handle, [__('pl.income'), number_format($data['collected'], 2, '.', '')]);
        fputcsv($handle, [__('pl.earned'), number_format($data['earned'], 2, '.', '')]);
        fputcsv($handle, [__('pl.held'), number_format($data['held_for_subscribers'], 2, '.', '')]);
        fputcsv($handle, [__('pl.credit_added'), number_format($data['credit_added'], 2, '.', '')]);
        fputcsv($handle, [__('pl.credit_spent'), number_format($data['credit_spent'], 2, '.', '')]);
        fputcsv($handle, [__('pl.rate'), number_format($data['rate'], 1, '.', '').'%']);
        fputcsv($handle, [__('pl.expenses_total'), number_format($data['expenses'], 2, '.', '')]);
        fputcsv($handle, [__('pl.net'), number_format($data['net'], 2, '.', '')]);
        fputcsv($handle, []);

        fputcsv($handle, [__('pl.by_category')]);
        fputcsv($handle, [__('t.category'), __('t.count'), __('t.amount')]);
        foreach ($data['byCategory'] as $row) {
            fputcsv($handle, [$row['label'], $row['count'], number_format($row['total'], 2, '.', '')]);
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return response()->streamDownload(
            fn () => print $contents,
            'profit-loss-'.$data['month'].'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    public function render()
    {
        return view('livewire.admin.pages.profit-loss', ['report' => $this->report()]);
    }
}
