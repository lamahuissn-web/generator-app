<?php

namespace App\Admin\Tables;

use Illuminate\Database\Eloquent\Builder;

/**
 * The receipts log inside a subscriber's file.
 *
 * The /payments list stays as it is: the subscriber's name in every row is how the office finds
 * a receipt there. On the subscriber's own file that column is the page title, so it is dropped
 * and the rest is kept whole — which invoices the money cleared, how it arrived, who took it —
 * because that is what a subscriber disputes, and the receipt is printed from this row.
 */
class SubscriberPaymentsTable extends PaymentsTable
{
    /**
     * A receipt has no cycle of its own: it is applied to invoices, oldest first, so it belongs
     * to a cycle when any of the invoices it cleared is in it. The rule itself lives on the model
     * (Payment::scopeForPeriod) because the file's totals need exactly the same definition.
     */
    public function scopePeriod(Builder $query, string $period): void
    {
        $query->forPeriod($period);
    }

    public function columns(): array
    {
        return array_values(array_filter(
            parent::columns(),
            fn (array $column): bool => $column['key'] !== 'subscriber.name',
        ));
    }

    public function emptyState(): string
    {
        return __('file.empty_payments');
    }
}
