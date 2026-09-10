<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\Bill;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Bills, as the /bills list and as a tab on a subscriber's profile. */
class BillsTable extends TableDefinition
{
    public function model(): string
    {
        return Bill::class;
    }

    public function query(): Builder
    {
        return Bill::query()->with(['subscriber:id,name', 'reading:id,previous_reading,current_reading']);
    }

    public function searchable(): array
    {
        return ['number', 'subscriber.name', 'period'];
    }

    public function defaultSort(): array
    {
        return ['period', 'desc'];
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'number',
                'label' => __('t.bill_no'),
                'searchable' => true,
                'sortable' => true,
                'render' => fn (Bill $r): string => $r->number ? ltr_isolate($r->number) : '—',
            ],
            [
                'key' => 'subscriber.name',
                'label' => __('t.subscriber'),
                'sortable' => true,
                'searchable' => true,
                'render' => fn (Bill $r): string => $r->subscriber?->name ?? '—',
            ],
            ['key' => 'period', 'label' => __('t.period'), 'sortable' => true, 'searchable' => true],
            [
                'key' => 'type',
                'label' => __('t.type'),
                'type' => 'badge',
                'sortable' => true,
                'color' => fn (Bill $r): string => match ($r->type) {
                    Bill::TYPE_SPECIAL => 'warning',
                    Subscriber::TYPE_FLAT => 'muted',
                    default => 'info',
                },
                'render' => fn (Bill $r): string => match ($r->type) {
                    Bill::TYPE_SPECIAL => __('t.type_special'),
                    Subscriber::TYPE_FLAT => __('t.type_flat'),
                    default => __('t.type_metered'),
                },
            ],
            [
                'key' => 'title',
                'label' => __('t.invoice_subject'),
                'hidden' => true,
                'on_mobile' => false,
                'render' => fn (Bill $r): string => $r->title ?: '—',
            ],
            [
                'key' => 'consumption',
                'label' => __('t.kwh'),
                'type' => 'number',
                'decimals' => 1,
                'align' => 'end',
                'hidden' => true,
                'on_mobile' => false,
            ],
            [
                'key' => 'total',
                'label' => __('t.total'),
                'type' => 'money',
                'sortable' => true,
                'align' => 'end',
                'render' => fn (Bill $r): string => money($r->total),
            ],
            [
                'key' => 'prev_debt',
                'label' => __('t.prev_debt'),
                'type' => 'money',
                'align' => 'end',
                'hidden' => true,
                'on_mobile' => false,
                'render' => fn (Bill $r): string => money($r->prev_debt),
                'color' => fn (Bill $r): string => (float) $r->prev_debt > 0 ? 'danger' : 'muted',
            ],
            [
                'key' => 'paid_amount',
                'label' => __('t.paid'),
                'type' => 'money',
                'sortable' => true,
                'align' => 'end',
                'render' => fn (Bill $r): string => money($r->paid_amount),
            ],
            [
                'key' => 'status',
                'label' => __('t.status'),
                'type' => 'badge',
                'color' => fn (Bill $r): string => match ($r->paymentStatus()) {
                    'paid' => 'success',
                    'partial' => 'warning',
                    default => 'danger',
                },
                'render' => fn (Bill $r): string => __('portal.'.$r->paymentStatus()),
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
                'options_from' => fn (): array => Bill::query()->distinct()->orderByDesc('period')
                    ->pluck('period', 'period')->all(),
                'apply' => fn (Builder $query, $value) => $query->where('period', $value),
            ],
            [
                'key' => 'type',
                'label' => __('t.type'),
                'type' => 'select',
                'options' => [
                    Subscriber::TYPE_METERED => __('t.type_metered'),
                    Subscriber::TYPE_FLAT => __('t.type_flat'),
                    Bill::TYPE_SPECIAL => __('t.type_special'),
                ],
                'apply' => fn (Builder $query, $value) => $query->where('type', $value),
            ],
            [
                'key' => 'unpaid',
                // Labelled with a field-style noun, not the sentence 「unpaid / partial only」:
                // every ternary renders as "<label> — All", so a sentence there reads as
                // "unpaid / partial only — all".
                'label' => __('f.payment_state'),
                'type' => 'ternary',
                'true_label' => __('f.unpaid_only'),
                'false_label' => __('portal.settled'),
                'apply' => fn (Builder $query, $value) => $value
                    ? $query->whereColumn('paid_amount', '<', 'total')
                    : $query->whereColumn('paid_amount', '>=', 'total'),
            ],
        ];
    }

    public function rowActions(): array
    {
        return [
            [
                'key' => 'print',
                'label' => __('prt.print'),
                'icon' => 'ki-printer',
                'new_tab' => true,
                'url' => fn (Bill $r) => route('print.bill', ['bill' => $r->id, 'size' => config('pdf.default_bill_size')]),
            ],
            [
                'key' => 'deleteSpecial',
                'label' => __('a.del_special'),
                'icon' => 'ki-trash',
                'action' => 'deleteSpecialInvoice',
                'confirm' => __('a.del_special_desc'),
                'hidden' => fn (Bill $r): bool => ! $r->isSpecial(),
            ],
            [
                'key' => 'deleteFlat',
                'label' => __('a.del_bill'),
                'icon' => 'ki-trash',
                'action' => 'deleteFlatBill',
                'confirm' => __('a.del_flat_guard'),
                'hidden' => fn (Bill $r): bool => $r->type !== Subscriber::TYPE_FLAT || (float) $r->paid_amount > 0,
            ],
        ];
    }

    public function runAction(string $key, Model $row): array
    {
        /** @var Bill $row */
        if ($key === 'deleteSpecialInvoice') {
            BillingService::deleteSpecialInvoice($row);

            return ['ok' => true, 'message' => __('a.del_special_ok')];
        }

        if ($key === 'deleteFlatBill') {
            BillingService::deleteFlatBill($row);

            return ['ok' => true, 'message' => __('a.del_bill_ok')];
        }

        throw new \RuntimeException(__('t.action_not_allowed'));
    }
}
