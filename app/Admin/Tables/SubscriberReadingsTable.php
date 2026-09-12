<?php

namespace App\Admin\Tables;

use App\Models\Reading;
use Illuminate\Support\HtmlString;

/**
 * The meter log inside a subscriber's file.
 *
 * The /readings list answers "whose reading is this?" — it carries the house, the street and who
 * typed the number. On a page that IS one house those columns are answered by the page itself, so
 * they are dropped and the room goes to the two facts the file is read for: the pair of readings
 * as one cell ("4478 → 4512", which the office says out loud as one fact) and the invoice that
 * reading produced. That link is the one the reading board only knows how to answer backwards.
 */
class SubscriberReadingsTable extends ReadingsTable
{
    public function columns(): array
    {
        $columns = [];

        foreach (parent::columns() as $column) {
            if (in_array($column['key'], ['subscriber.name', 'route', 'entered_by', 'previous_reading', 'current_reading'], true)) {
                continue;
            }

            $columns[] = $column;

            if ($column['key'] === 'period') {
                $columns[] = [
                    'key' => 'reading_pair',
                    'label' => __('file.reading'),
                    'align' => 'end',
                    'render' => fn (Reading $reading): string => reading_pair(
                        $reading->previous_reading,
                        $reading->current_reading,
                    ),
                ];
            }
        }

        $columns[] = $this->billColumn();

        return $columns;
    }

    /** Which invoice this reading produced, or the honest absence of one. */
    private function billColumn(): array
    {
        return [
            'key' => 'bill',
            'label' => __('mdl.bill'),
            'on_mobile' => false,
            'render' => function (Reading $reading): HtmlString {
                $bill = $reading->bill;

                // A meter's starting point (a baseline) bills nothing at all: saying so beats an
                // empty cell, because "no invoice" IS the answer to "and what did he pay for this?".
                if ($bill === null) {
                    return new HtmlString('<span class="text-xs text-muted-foreground/60">'.e(__('file.no_bill')).'</span>');
                }

                $url = route('print.bill', ['bill' => $bill->id, 'size' => config('pdf.default_bill_size')]);

                return new HtmlString(
                    '<a href="'.e($url).'" target="_blank" rel="noopener"'
                    .' class="amount-cell font-medium text-mono underline decoration-dotted underline-offset-4">'
                    .e(ltr_isolate($bill->number ?: 'INV-'.$bill->period))
                    .'</a>'
                );
            },
        ];
    }

    /**
     * The invoice is linked from its own column here, so the row keeps only the corrective
     * actions: this morning's mistake, and a closed month that needs a written reason.
     */
    public function rowActions(): array
    {
        return array_values(array_filter(
            parent::rowActions(),
            fn (array $action): bool => $action['key'] !== 'bill',
        ));
    }

    public function emptyState(): string
    {
        return __('file.empty_readings');
    }
}
