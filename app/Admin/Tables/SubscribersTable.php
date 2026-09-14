<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\Subscriber;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The subscriber list — the screen an operator lives in.
 *
 * Two columns are not plain columns: "outstanding" is a SUM over bills (so it sorts by
 * raw SQL, not by a field) and "name" carries the meter/apps line under it, which is how
 * a collector recognises a household without opening the profile.
 */
class SubscribersTable extends TableDefinition
{
    public function model(): string
    {
        return Subscriber::class;
    }

    public function query(): Builder
    {
        return Subscriber::query()->with('route:id,name');
    }

    public function searchable(): array
    {
        return ['name', 'meter_no', 'phone'];
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function rowUrl(Model $row): ?string
    {
        return route('admin.subscribers.show', $row);
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'name',
                'label' => __('t.name'),
                'type' => 'two-line',
                'sortable' => true,
                'searchable' => true,
                'sub' => fn (Subscriber $r): ?string => collect([
                    $r->meter_no ? __('t.meter').' '.ltr_isolate($r->meter_no) : null,
                    $r->amperage ? ltr_isolate($r->amperage.'A') : null,
                ])->filter()->implode(' · ') ?: null,
            ],
            [
                'key' => 'route.name',
                'label' => __('t.route'),
                'sortable' => true,
                'searchable' => true,
                'on_mobile' => false,
            ],
            [
                'key' => 'type',
                'label' => __('t.type'),
                'type' => 'badge',
                'sortable' => true,
                'badge' => 'muted',
                'color' => fn (Subscriber $r): string => $r->isMetered() ? 'info' : 'muted',
                'render' => fn (Subscriber $r): string => $r->isMetered() ? __('t.type_metered') : __('t.type_flat'),
            ],
            [
                'key' => 'phone',
                'label' => __('t.phone'),
                'searchable' => true,
                'on_mobile' => false,
                'render' => fn (Subscriber $r): string => $r->phone ? ltr_isolate($r->phone) : '—',
            ],
            [
                'key' => 'outstanding',
                'label' => __('t.outstanding'),
                'type' => 'money',
                'sortable' => true,
                // The figure is a SUM over bills, so it has to sort by the aggregate.
                'sort_by' => '(SELECT COALESCE(SUM(b.total - b.paid_amount), 0) FROM bills b WHERE b.subscriber_id = subscribers.id)',
                'align' => 'end',
                'render' => fn (Subscriber $r): string => money($r->outstanding()),
                'color' => fn (Subscriber $r): string => $r->outstanding() > 0 ? 'danger' : 'success',
            ],
            [
                'key' => 'status',
                'label' => __('t.status'),
                'type' => 'badge',
                'sortable' => true,
                'color' => fn (Subscriber $r): string => $r->status === Subscriber::STATUS_ACTIVE ? 'success' : 'muted',
                'render' => fn (Subscriber $r): string => $r->status === Subscriber::STATUS_ACTIVE
                    ? __('portal.active') : __('portal.inactive'),
            ],
            // An exception column: it is empty for every healthy subscriber, so it sits
            // after the figures the operator actually scans rather than taking position 2.
            [
                'key' => 'meter_broken_reported_at',
                'label' => __('t.meter_report'),
                'type' => 'badge',
                'badge' => 'danger',
                'align' => 'start',
                'on_mobile' => false,
                // A report is an exception: showing "—" for the normal case would make
                // the column the widest thing on the screen for no information.
                'render' => fn (Subscriber $r) => $r->hasBrokenMeterReport() ? __('t.meter_broken_badge') : '',
            ],
            [
                'key' => 'created_at',
                'label' => __('t.created'),
                'type' => 'date',
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
                'key' => 'route_id',
                'label' => __('t.route'),
                'type' => 'select',
                'options_from' => fn (): array => \App\Models\Route::query()->orderBy('name')->pluck('name', 'id')->all(),
                'apply' => fn (Builder $query, $value) => $query->where('route_id', $value),
            ],
            [
                'key' => 'type',
                'label' => __('t.type'),
                'type' => 'select',
                'options' => [
                    Subscriber::TYPE_METERED => __('t.type_metered'),
                    Subscriber::TYPE_FLAT => __('t.type_flat'),
                ],
                'apply' => fn (Builder $query, $value) => $query->where('type', $value),
            ],
            [
                'key' => 'status',
                'label' => __('t.status'),
                'type' => 'ternary',
                'true_label' => __('portal.active'),
                'false_label' => __('portal.inactive'),
                'apply' => fn (Builder $query, $value) => $query->where('status', $value ? Subscriber::STATUS_ACTIVE : Subscriber::STATUS_INACTIVE),
            ],
            [
                'key' => 'meter_report',
                'label' => __('t.meter_report'),
                'type' => 'ternary',
                'true_label' => __('t.meter_report_yes'),
                'false_label' => __('t.meter_report_no'),
                'apply' => fn (Builder $query, $value) => $value
                    ? $query->whereNotNull('meter_broken_reported_at')
                    : $query->whereNull('meter_broken_reported_at'),
            ],
        ];
    }

    public function rowActions(): array
    {
        return [
            [
                'key' => 'collect',
                'label' => __('a.collect'),
                'icon' => 'ki-wallet',
                'variant' => 'default',
                'url' => fn (Subscriber $r) => route('admin.subscribers.show', ['subscriber' => $r, 'action' => 'collect']),
                // The rule lives on the action and is read from it, so the row and the file can
                // never disagree about who is collectable.
                'hidden' => fn (Subscriber $r): bool => ! app(\App\Admin\Actions\CollectPayment::class)->visible($r),
            ],
            [
                'key' => 'statement',
                'label' => __('col.statement'),
                'icon' => 'ki-printer',
                'variant' => 'default',
                'new_tab' => true,
                'url' => fn (Subscriber $r) => route('print.statement', ['subscriber' => $r->id, 'size' => 'a4']),
            ],
            [
                'key' => 'edit',
                'label' => __('t.edit'),
                'icon' => 'ki-pencil',
                'variant' => 'default',
                'url' => fn (Subscriber $r) => route('admin.subscribers.edit', $r),
            ],
            [
                'key' => 'delete',
                'label' => __('t.delete'),
                'icon' => 'ki-trash',
                'variant' => 'danger',
                'method' => 'deleteRecord',
            ],
        ];
    }

    public function canDelete(Model $row): bool
    {
        return true;
    }

    public function deleteWarning(Model $row): string
    {
        return $row->bills()->exists()
            ? __('a.del_sub_history')
            : __('a.del_sub_plain');
    }

    public function deleteRecord(Model $row): void
    {
        $row->delete();
    }
}
