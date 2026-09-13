<?php

namespace App\Admin\Tables;

use App\Admin\Actions\CorrectLockedReading;
use App\Admin\TableDefinition;
use App\Models\Reading;
use App\Models\Route;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CycleLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Readings, both as the /readings list and as a tab on a subscriber's profile.
 *
 * This is the office's record of what was measured, so it is built to be ANSWERED rather than
 * only read: which area, which collector, which meter — and the whole filtered set out as a
 * spreadsheet, because the office works this list on paper and by phone. "Who is still missing"
 * is a different question with its own screen (the reading board); a reading that exists is what
 * this list is for.
 */
class ReadingsTable extends TableDefinition
{
    public function model(): string
    {
        return Reading::class;
    }

    public function query(): Builder
    {
        return Reading::query()->with([
            'enteredBy:id,name',
            // The route and the meter number are filterable/searchable here, so the columns that
            // print them must not each fire their own query per row.
            'subscriber:id,name,route_id,meter_no,phone',
            'subscriber.route:id,name',
            'bill:id,reading_id,number,total,prev_debt',
        ]);
    }

    public function searchable(): array
    {
        // A meter number is what the office is handed over the phone ("عندك قراءة للعدّاد 4478؟"),
        // and the phone number is what identifies a subscriber when two share a name.
        return ['period', 'subscriber.name', 'subscriber.meter_no', 'subscriber.phone'];
    }

    public function defaultSort(): array
    {
        return ['period', 'desc'];
    }

    /** A row is a reading of one house: it opens that house. */
    public function rowUrl(Model $row): ?string
    {
        return $row instanceof Reading && $row->subscriber_id
            ? route('admin.subscribers.show', $row->subscriber_id)
            : null;
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'subscriber.name',
                'label' => __('t.subscriber'),
                'sortable' => true,
                'searchable' => true,
                'render' => function (Reading $r): string {
                    $name = $r->subscriber?->name ?? '—';
                    $meter = $r->subscriber?->meter_no;

                    // The meter number belongs to the name on this list: it is how the operator
                    // matches a row against the paper slip or the phone call they are holding.
                    return $meter === null ? $name : $name.' · '.__('t.meter').' '.$meter;
                },
            ],
            [
                'key' => 'route',
                'label' => __('t.route'),
                'on_mobile' => false,
                'render' => fn (Reading $r): string => $r->subscriber?->route?->name ?? '—',
            ],
            [
                'key' => 'period',
                'label' => __('t.period'),
                'sortable' => true,
                'searchable' => true,
                'render' => fn (Reading $r): string => period_label_i18n($r->period),
            ],
            [
                'key' => 'cycle',
                'label' => __('t.cycle_state'),
                'type' => 'badge',
                'on_mobile' => false,
                // Which cycles are history and which is the month being worked: the difference
                // decides whether a correction needs a written reason, so it is visible before the
                // operator reaches for a row action.
                'render' => fn (Reading $r): string => CycleLock::isClosed($r->period) ? __('t.cycle_closed') : '',
                'color' => fn (Reading $r): string => CycleLock::isClosed($r->period) ? 'muted' : 'success',
            ],
            [
                'key' => 'previous_reading',
                'label' => __('t.prev_reading'),
                'type' => 'number',
                'align' => 'end',
                'on_mobile' => false,
            ],
            ['key' => 'current_reading', 'label' => __('t.current_reading'), 'type' => 'number', 'align' => 'end'],
            ['key' => 'consumption', 'label' => __('t.kwh'), 'type' => 'number', 'decimals' => 1, 'align' => 'end'],
            [
                'key' => 'reading_source',
                'label' => __('t.reading_source'),
                'type' => 'badge',
                'on_mobile' => false,
                // An estimate or a meter swap is the exception; a normal read needs no chip.
                'render' => fn (Reading $r): string => $r->is_estimated
                    ? __('t.source_estimated')
                    : ($r->hasMeterSwap() ? __('t.meter_swap') : ''),
                'color' => fn (Reading $r): string => $r->is_estimated ? 'warning' : 'info',
            ],
            [
                'key' => 'entered_by',
                'label' => __('t.entered_by'),
                'on_mobile' => false,
                'render' => fn (Reading $r): string => $r->enteredBy?->name ?? '—',
            ],
            [
                'key' => 'created_at',
                'label' => __('t.when'),
                'type' => 'date',
                'format' => 'Y-m-d H:i',
                'sortable' => true,
                'hidden' => true,
                'on_mobile' => false,
            ],
        ];
    }

    public function filters(): array
    {
        return [
            [
                'key' => 'period',
                'label' => __('t.period'),
                // A calendar, not a list: the operator reaches any cycle — including one with no
                // reading yet, which is exactly what they come here looking for — and the dot says
                // which cycles hold something. Nothing is empty just because nobody listed it.
                'type' => 'month',
                'placeholder' => __('t.all_months'),
                'apply' => fn (Builder $query, $value) => $query->where('period', $value),
            ],
            [
                'key' => 'route_id',
                'label' => __('t.route'),
                'type' => 'select',
                'options_from' => fn (): array => Route::query()->orderBy('name')->pluck('name', 'id')->all(),
                'apply' => fn (Builder $query, $value) => $query->whereHas(
                    'subscriber',
                    fn (Builder $sub) => $sub->where('route_id', $value),
                ),
            ],
            [
                'key' => 'entered_by',
                'label' => __('t.entered_by'),
                'type' => 'select',
                'options_from' => fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all(),
                'apply' => fn (Builder $query, $value) => $query->where('entered_by', $value),
            ],
            [
                'key' => 'estimated',
                'label' => __('t.reading_source'),
                'type' => 'ternary',
                'true_label' => __('t.source_estimated'),
                'false_label' => __('t.source_real'),
                'apply' => fn (Builder $query, $value) => $query->where('is_estimated', (bool) $value),
            ],
        ];
    }

    public function toolbar(): array
    {
        return [
            [
                'key' => 'export',
                'label' => __('t.export'),
                'icon' => 'ki-file-down',
                'variant' => 'outline',
                'method' => 'exportCsv',
            ],
        ];
    }

    /** The headings of the cycle's spreadsheet — the same order the office reads the list in. */
    public function csvHeader(): array
    {
        return [
            __('t.subscriber'), __('t.route'), __('t.meter'), __('t.period'),
            __('t.prev_reading'), __('t.current_reading'), __('t.kwh'),
            __('t.reading_source'), __('t.entered_by'), __('prt.bill_no'), __('t.total'),
        ];
    }

    public function csvRow(Model $row): array
    {
        /** @var Reading $reading */
        $reading = $row;

        return [
            $reading->subscriber?->name ?? '',
            $reading->subscriber?->route?->name ?? '',
            $reading->subscriber?->meter_no ?? '',
            $reading->period,
            number_format((float) $reading->previous_reading, 0, '.', ''),
            number_format((float) $reading->current_reading, 0, '.', ''),
            // Unformatted numbers on purpose: a spreadsheet must be able to add them up, and
            // "1,234.5" imports as text.
            number_format((float) $reading->consumption, 1, '.', ''),
            $reading->is_estimated ? __('t.source_estimated') : __('t.source_real'),
            $reading->enteredBy?->name ?? '',
            $reading->bill?->number ?? '',
            $reading->bill === null
                ? ''
                : number_format((float) $reading->bill->total + (float) $reading->bill->prev_debt, 2, '.', ''),
        ];
    }

    public function rowActions(): array
    {
        return [
            [
                'key' => 'bill',
                'label' => __('a.open_bill'),
                'icon' => 'ki-receipt-square',
                'new_tab' => true,
                // The bill points AT the reading (bills.reading_id), not the other way round: a
                // reading has no bill_id column, and reading one off the model produced a route
                // with a missing parameter on every row that had a bill.
                'url' => fn (Reading $r): string => route('print.bill', [
                    'bill' => $r->bill->id,
                    'size' => config('pdf.default_bill_size'),
                ]),
                // A baseline reading (a meter's starting point) bills nothing, so it has none.
                'hidden' => fn (Reading $r): bool => $r->bill === null,
            ],
            [
                // Two deletes, never both on one row: this morning's mistake is housekeeping, and a
                // month that was closed and collected against is a correction that must be explained.
                'key' => 'delete',
                'label' => __('a.del_reading'),
                'icon' => 'ki-trash',
                'action' => 'deleteReading',
                'confirm' => __('a.del_reading_guard'),
                'hidden' => fn (Reading $r): bool => CycleLock::isClosed($r->period),
            ],
            [
                'key' => 'correct',
                'label' => __('a.correct_locked'),
                'action_form' => CorrectLockedReading::class,
                'hidden' => fn (Reading $r): bool => ! CycleLock::isClosed($r->period),
            ],
        ];
    }

    public function runAction(string $key, Model $row): array
    {
        if ($key !== 'deleteReading') {
            throw new \RuntimeException(__('t.action_not_allowed'));
        }

        /** @var Reading $row */
        BillingService::deleteReadingAndBill($row);

        return ['ok' => true, 'message' => __('a.del_reading_ok')];
    }
}
