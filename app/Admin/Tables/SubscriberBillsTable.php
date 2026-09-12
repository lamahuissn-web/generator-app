<?php

namespace App\Admin\Tables;

use App\Models\Bill;
use App\Services\Currency;

/**
 * The bills log inside a subscriber's file.
 *
 * Not a second table: the /bills list with the two things a page about ONE house makes redundant
 * or unfair — the subscriber's own name repeated on every row, and the consumption column hidden
 * to make room on a wide list. Here the consumption and the reading behind the bill sit next to
 * the amount, because "ليش آب 441$؟" is answered by the meter, and reading a bill out loud is
 * something the office does at the door.
 */
class SubscriberBillsTable extends BillsTable
{
    public function columns(): array
    {
        $columns = [];

        foreach (parent::columns() as $column) {
            if ($column['key'] === 'subscriber.name') {
                continue;
            }

            if ($column['key'] === 'consumption') {
                $column['hidden'] = false;
            }

            $columns[] = $column;

            if ($column['key'] === 'total' && Currency::isConfigured()) {
                $columns[] = [
                    'key' => 'total_lbp',
                    'label' => __('file.lbp_today'),
                    'align' => 'end',
                    'on_mobile' => false,
                    // The lira figure TODAY, not the lira of the day the invoice was issued: the
                    // street quotes a standing debt in today's money, and the label says so.
                    // The column only exists while a rate does — a heading over a column of blank
                    // cells is a promise the panel is not keeping.
                    'render' => fn (Bill $bill): ?string => lbp($bill->total),
                ];
            }

            if ($column['key'] === 'period') {
                $columns[] = [
                    'key' => 'reading',
                    'label' => __('file.reading_of_bill'),
                    'on_mobile' => false,
                    'render' => function (Bill $bill): string {
                        // An ad-hoc invoice bills a thing, not a month of consumption, and a
                        // flat-rate house has no meter: both say so instead of showing a blank
                        // cell that looks like missing data.
                        if ($bill->reading === null) {
                            return $bill->isSpecial() ? __('t.type_special') : __('file.no_reading');
                        }

                        return reading_pair($bill->reading->previous_reading, $bill->reading->current_reading);
                    },
                ];
            }
        }

        return $columns;
    }

    public function emptyState(): string
    {
        return __('file.empty_bills');
    }
}
