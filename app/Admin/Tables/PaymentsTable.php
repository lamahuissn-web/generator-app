<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\Payment;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Payments, as the /payments list and as a tab on a subscriber's profile. */
class PaymentsTable extends TableDefinition
{
    public function model(): string
    {
        return Payment::class;
    }

    public function query(): Builder
    {
        return Payment::query()->with(['subscriber:id,name', 'collector:id,name']);
    }

    public function searchable(): array
    {
        return ['number', 'subscriber.name'];
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'number',
                'label' => __('t.receipt_no'),
                'searchable' => true,
                'sortable' => true,
                'render' => fn (Payment $r): string => $r->number ? ltr_isolate($r->number) : '—',
            ],
            [
                'key' => 'subscriber.name',
                'label' => __('t.subscriber'),
                'sortable' => true,
                'searchable' => true,
                'render' => fn (Payment $r): string => $r->subscriber?->name ?? '—',
            ],
            [
                'key' => 'amount',
                'label' => __('t.amount'),
                'type' => 'money',
                'sortable' => true,
                'align' => 'end',
                'render' => fn (Payment $r): string => money($r->amount),
            ],
            [
                'key' => 'method',
                'label' => __('t.payment_method'),
                'type' => 'badge',
                'color' => fn (Payment $r): string => match ($r->method) {
                    Payment::METHOD_CASH_USD => 'success',
                    Payment::METHOD_CASH_LBP => 'info',
                    Payment::METHOD_OMT => 'warning',
                    default => 'muted',
                },
                'render' => fn (Payment $r): string => $r->methodLabel(),
            ],
            [
                'key' => 'settled_bills',
                'label' => __('t.settled_invoices'),
                'on_mobile' => false,
                // Which invoices this money cleared: "paid $30" without the invoice it
                // settled is what starts disputes.
                'render' => fn (Payment $r): string => $r->settledBillsLabel() ?: '—',
            ],
            [
                'key' => 'collector.name',
                'label' => __('t.collected_by'),
                'on_mobile' => false,
                'render' => fn (Payment $r): string => $r->collector?->name ?? __('col.unknown_collector'),
            ],
            [
                'key' => 'created_at',
                'label' => __('t.when'),
                'type' => 'date',
                'format' => 'Y-m-d H:i',
                'sortable' => true,
                'on_mobile' => false,
            ],
        ];
    }

    public function filters(): array
    {
        return [
            [
                'key' => 'method',
                'label' => __('t.payment_method'),
                'type' => 'select',
                'options' => Payment::methodOptions(),
                'apply' => fn (Builder $query, $value) => $query->where('method', $value),
            ],
            [
                'key' => 'collector_id',
                'label' => __('col.collector'),
                'type' => 'select',
                'options_from' => fn (): array => User::query()->where('role', 'collector')
                    ->orderBy('name')->pluck('name', 'id')->all(),
                'apply' => fn (Builder $query, $value) => $query->where('collector_id', $value),
            ],
        ];
    }

    public function rowActions(): array
    {
        return [
            [
                'key' => 'receipt',
                'label' => __('t.receipt'),
                'icon' => 'ki-printer',
                'new_tab' => true,
                'url' => fn (Payment $r) => route('print.receipt', ['payment' => $r->id, 'size' => '80mm']),
            ],
            [
                'key' => 'reverse',
                'label' => __('a.reverse_payment'),
                'icon' => 'ki-trash',
                'action' => 'reversePayment',
                'confirm' => __('a.reverse_desc'),
            ],
        ];
    }

    public function runAction(string $key, Model $row): array
    {
        if ($key !== 'reversePayment') {
            throw new \RuntimeException(__('t.action_not_allowed'));
        }

        /** @var Payment $row */
        BillingService::reversePayment($row);

        return ['ok' => true, 'message' => __('a.reverse_ok')];
    }
}
