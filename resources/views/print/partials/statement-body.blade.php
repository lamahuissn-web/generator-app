@php
    /**
     * Customer-facing account statement.
     *
     * This is the paper the subscriber is handed: dates, what happened, whether it was an
     * invoice or a payment, the amount, and the balance left after it. The word «مدين»/«دائن»
     * is deliberately absent — it means nothing to a customer — and the two columns that
     * carried them collapse into one «النوع» column. The figures are the same ones the ledger
     * closes on: إجمالي الفواتير + رصيد سابق − إجمالي المدفوع = المبلغ المتبقي.
     */
    $isRtl = app()->isLocale('ar');
    $co = $co ?? \App\Services\PdfService::company();
    $t = $statement['totals'];
    $rows = $statement['rows'];

    $opening = (float) $t['opening'];
    $due = (float) $t['closing'];

    /*
     * An amount as it has to appear on paper.
     *
     * Two mPDF behaviours are corrected here, both of them printed wrong without it:
     *
     *  - a negative balance is prepaid money, so the sign travels in front of the currency
     *    ("−$0.80") instead of PHP's "$-0.80", which a customer reads as a debt — and mPDF also
     *    drops a bare "−" on the far side of the digits;
     *  - an amount that follows an Arabic line inside the same cell gets its run reversed, so
     *    the summary boxes printed "101.70$" instead of "$101.70".
     *
     * The fragment is built in PHP and printed unescaped at the call site because the sign has to
     * travel with the amount; money() itself is left exactly as the rest of the program formats it.
     */
    $amount = function (float $value): string {
        $text = money(abs($value));

        return '<span class="ltr">'.($value < 0 ? '−' : '').$text.'</span>';
    };
@endphp

<table>
    <tr>
        <td style="width:58%">
            <div class="co-name">{{ $co['name'] }}</div>
            @if ($co['address'])
                <div class="co-meta">{{ $co['address'] }}</div>
            @endif
            @if ($co['phone'])
                <div class="co-meta">{{ __('prt.phone') }}: <span class="ltr">{{ $co['phone'] }}</span></div>
            @endif
        </td>
        <td style="width:42%">
            <div class="doc-title">{{ __('col.statement') }}</div>
            <div class="doc-meta">{{ $label }}</div>
        </td>
    </tr>
</table>

<hr class="rule">

{{-- The subscriber's own file: two label/value pairs per line, so the block is balanced. --}}
<table class="cstmt-info">
    <tr>
        <td class="k">{{ __('col.cst_name') }}</td>
        <td class="v">{{ $subscriber->name }}</td>
        <td class="k">{{ __('prt.route') }}</td>
        <td class="v">{{ $subscriber->route?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">{{ __('prt.phone') }}</td>
        <td class="v"><span class="ltr">{{ $subscriber->phone ?: '—' }}</span></td>
        <td class="k">{{ __('col.cst_meter_no') }}</td>
        <td class="v"><span class="ltr">{{ $subscriber->meter_no ?: '—' }}</span></td>
    </tr>
    <tr>
        <td class="k">{{ __('col.st_type') }}</td>
        <td class="v">{{ $subscriber->isMetered() ? __('gen.type_metered') : __('gen.type_flat') }}</td>
        <td class="k">{{ __('prt.issue_date') }}</td>
        <td class="v"><span class="ltr">{{ now()->format('Y-m-d') }}</span></td>
    </tr>
</table>

<table class="cstmt">
    <thead>
        <tr>
            <th class="num" style="width:15%">{{ __('col.st_date') }}</th>
            <th style="width:42%">{{ __('col.st_description') }}</th>
            <th class="kind" style="width:12%">{{ __('col.cst_type') }}</th>
            <th class="num" style="width:15%">{{ __('prt.amount') }}</th>
            <th class="num" style="width:16%">{{ __('col.st_balance') }}</th>
        </tr>
    </thead>
    <tbody>
        @if ($from && $opening != 0.0)
            <tr class="opening">
                <td class="num"><span class="ltr">{{ $from }}</span></td>
                <td class="desc">{{ __('col.st_opening') }}</td>
                <td class="kind">—</td>
                <td class="num"></td>
                <td class="num bal">{!! $amount($opening) !!}</td>
            </tr>
        @endif

        @forelse ($rows as $row)
            @php
                $isPayment = $row['kind'] === 'payment';
                $rowAmount = $isPayment ? (float) $row['settled'] : (float) $row['due'];
                $invoices = $isPayment ? ($row['invoices'] ?? []) : [];
                $invoiceNo = $row['invoice_no'] ?? null;
            @endphp
            <tr class="{{ $loop->iteration % 2 === 0 ? 'alt' : '' }}">
                <td class="num"><span class="ltr">{{ $row['date']?->format('Y-m-d') }}</span></td>
                <td class="desc">
                    <span class="line-main">
                        @if ($isPayment && $invoices !== [])
                            {{ __('col.cst_payment_line', ['invoices' => implode('، ', $invoices)]) }}
                        @else
                            {{ $row['description'] }}
                        @endif
                    </span>

                    {{-- Who took the money and how it was paid belong on the payment line itself;
                         the invoice number only repeats the description when that names it. --}}
                    @if ($isPayment)
                        <span class="line-meta">{{ __('col.cst_payment_meta', [
                            'method' => $row['method'] ?? '—',
                            'collector' => $row['collector_name'] ?? '—',
                        ]) }}</span>
                    @elseif ($invoiceNo && ! str_contains((string) $row['description'], (string) $invoiceNo))
                        <span class="line-meta">{{ __('col.cst_invoice_no', ['number' => $invoiceNo]) }}</span>
                    @endif
                </td>
                <td class="kind">
                    <span class="chip {{ $isPayment ? 'chip-paid' : 'chip-bill' }}">
                        {{ $isPayment ? __('col.cst_kind_payment') : __('col.cst_kind_bill') }}
                    </span>
                </td>
                <td class="num">{!! $amount($rowAmount) !!}</td>
                <td class="num bal">{!! $amount((float) $row['balance']) !!}</td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="desc">{{ __('col.st_empty') }}</td>
            </tr>
        @endforelse
    </tbody>
</table>

{{-- The bottom line, in the order a customer reads it: what was billed, what was paid,
     what is left. --}}
@php
    // Three boxes when there is a carried-over balance, two when the sheet covers everything.
    $hasOpening = $opening != 0.0;
@endphp
<table class="cstmt-sum">
    <tr>
        @if ($hasOpening)
            <td class="sum-box sum-opening sum-w3">
                <div class="sum-k">{{ __('col.cst_opening') }}</div>
                <div class="sum-v">{!! $amount($opening) !!}</div>
            </td>
        @endif
        <td class="sum-box sum-inv {{ $hasOpening ? 'sum-w3-wide' : 'sum-w2' }}">
            <div class="sum-k">{{ __('col.cst_total_bills') }}</div>
            <div class="sum-v">{!! $amount((float) $t['debit']) !!}</div>
        </td>
        <td class="sum-box sum-paid {{ $hasOpening ? 'sum-w3' : 'sum-w2' }}">
            <div class="sum-k">{{ __('col.cst_total_paid') }}</div>
            <div class="sum-v">{!! $amount((float) $t['credit']) !!}</div>
        </td>
    </tr>
</table>

@php
    $dueClass = $due > 0 ? 'due-owed' : ($due < 0 ? 'due-credit' : 'due-settled');
    $dueLabel = $due > 0 ? __('col.cst_due') : ($due < 0 ? __('col.cst_credit') : __('col.cst_settled'));
    $dueNote = $due > 0
        ? __('col.cst_due_note')
        : ($due < 0 ? __('col.cst_credit_note') : __('col.cst_settled_note'));
@endphp

<table class="cstmt-due {{ $dueClass }}">
    <tr>
        <td class="due-k">
            {{ $dueLabel }}
            <span class="due-note">{{ $dueNote }}</span>
        </td>
        <td class="due-v">{!! $amount($due) !!}</td>
    </tr>
</table>

@if ($hidden = $statement['rows_total'] - $statement['rows_shown'])
    <div class="note">{{ __('col.cst_earlier', ['count' => $hidden, 'from' => $from]) }}</div>
@endif

@if (collect($rows)->contains(fn ($row) => (float) $row['balance'] < 0))
    <div class="note">{{ __('col.cst_negative_note') }}</div>
@endif

<div class="cstmt-foot">
    {{ $co['footer'] ?: __('prt.footer_note') }}
</div>

<div class="thanks">{{ __('prt.thanks') }}</div>
