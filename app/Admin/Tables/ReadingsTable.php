<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\Reading;
use App\Services\BillingService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Readings, both as the /readings list and as a tab on a subscriber's profile. */
class ReadingsTable extends TableDefinition
{
    public function model(): string
    {
        return Reading::class;
    }

    public function query(): Builder
    {
        return Reading::query()->with(['enteredBy:id,name', 'subscriber:id,name']);
    }

    public function searchable(): array
    {
        return ['period', 'subscriber.name'];
    }

    public function defaultSort(): array
    {
        return ['period', 'desc'];
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'subscriber.name',
                'label' => __('t.subscriber'),
                'sortable' => true,
                'searchable' => true,
                'render' => fn (Reading $r): string => $r->subscriber?->name ?? '—',
            ],
            ['key' => 'period', 'label' => __('t.period'), 'sortable' => true, 'searchable' => true],
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
                'type' => 'select',
                'options_from' => fn (): array => Reading::query()->distinct()->orderByDesc('period')
                    ->pluck('period', 'period')->all(),
                'apply' => fn (Builder $query, $value) => $query->where('period', $value),
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

    public function rowActions(): array
    {
        return [
            [
                'key' => 'delete',
                'label' => __('a.del_reading'),
                'icon' => 'ki-trash',
                'action' => 'deleteReading',
                'confirm' => __('a.del_reading_guard'),
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
