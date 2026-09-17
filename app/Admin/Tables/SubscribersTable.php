<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\Subscriber;
use App\Services\Audit;
use App\Services\SubscriberImport;
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

    /**
     * The export carries the SAME columns as the import template, from one list, so the office can
     * take the data out, edit it in Excel and put it back in without translating column names.
     */
    public function csvHeader(): array
    {
        return SubscriberImport::headers();
    }

    /**
     * @return array<int, string>
     */
    public function csvRow(Model $row): array
    {
        /** @var Subscriber $row */
        return [
            (string) $row->name,
            (string) ($row->route?->name ?? ''),
            (string) ($row->meter_no ?? ''),
            (string) ($row->phone ?? ''),
            (string) ($row->address ?? ''),
            SubscriberImport::typeLabel($row->type),
            SubscriberImport::statusLabel($row->status),
            $row->amperage === null ? '' : (string) $row->amperage,
            SubscriberImport::modeLabel($row->maintenance_mode),
            (float) $row->maintenance_fee > 0 ? number_format((float) $row->maintenance_fee, 2, '.', '') : '',
            $row->flat_fee === null ? '' : number_format((float) $row->flat_fee, 2, '.', ''),
            (string) ($row->notes ?? ''),
            $row->opening_reading === null ? '' : number_format((float) $row->opening_reading, 2, '.', ''),
            (string) ($row->opening_period ?? ''),
        ];
    }

    public function csvFilename(): string
    {
        return 'subscribers-'.now()->format('Y-m-d').'.csv';
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
                // His own money, waiting with the office. Shown on the list because a balance nobody
                // can see is a balance nobody returns: it comes off his next invoice (credit_applied),
                // and a subscriber who leaves is owed it at the door.
                'key' => 'credit',
                'label' => __('t.credit'),
                'type' => 'money',
                'sortable' => true,
                'render' => fn (Subscriber $r): ?string => (float) $r->credit > 0 ? money($r->credit) : null,
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

    /**
     * A subscriber who exists in the ledger CANNOT be deleted — the history is the money.
     *
     * Deleting one cascades: the database takes his readings, his invoices, his receipts and their
     * allocations with him, and nothing anywhere records that they existed. The receipt and invoice
     * numbering keeps a permanent hole (the numbers are issued from a counter and never reused), so
     * the office ends up with gaps in the paper it can neither explain nor restore. The status is
     * built for exactly this: «موقوف» takes him out of the round, the readings and the billing while
     * keeping his file, his debt and his history — and his debt stays collectable (principle VI).
     *
     * A row with NO trace is still deletable: somebody typed a subscriber twice, or created one to
     * see how the form works, and deleting that is honest correction rather than destruction. A
     * blanket ban would push the office into inventing a fake «موقوف» for it, which is how a real
     * stopped-subscriber report fills up with people who never existed.
     */
    public function canDelete(Model $row): bool
    {
        return ! $this->hasLedgerTrace($row);
    }

    /** The refusal names the way out, so the button teaches instead of looking broken. */
    public function deleteRefusal(Model $row): ?string
    {
        return $this->hasLedgerTrace($row) ? __('a.del_sub_refused') : null;
    }

    /** Anything that would be destroyed with him, or that names him. */
    private function hasLedgerTrace(Model $row): bool
    {
        return $row->bills()->exists()
            || $row->payments()->exists()
            || $row->readings()->exists();
    }

    /** Only a subscriber with no trace gets this far, so the warning is the plain one. */
    public function deleteWarning(Model $row): string
    {
        return __('a.del_sub_plain');
    }

    public function deleteRecord(Model $row): void
    {
        // Even a row that never existed in the ledger is a deliberate act: the trail should be able
        // to say who decided it was a mistake, and what the row said before it left.
        Audit::log('subscriber.deleted', $row, [
            'name' => $row->name,
            'meter_no' => $row->meter_no,
            'route' => $row->route?->name,
            'status' => $row->status,
            'period_created' => $row->created_at?->toDateString(),
        ]);

        $row->delete();
    }
}
