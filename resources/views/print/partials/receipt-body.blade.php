@php
    $isRtl = app()->isLocale('ar');
    $co = $co ?? \App\Services\PdfService::company();
    $payment->loadMissing(['collector', 'allocations.bill']);
    $settled = (float) $remaining <= 0;
@endphp

<table class="head">
    <tr>
        <td style="width:55%">
            <div class="co-name">{{ $co['name'] }}</div>
            @if ($co['phone'])
                <div class="co-meta">{{ __('prt.phone') }}: <span class="ltr">{{ $co['phone'] }}</span></div>
            @endif
        </td>
        <td style="width:45%">
            <div class="doc-title">{{ __('prt.receipt_title') }}</div>
            <div class="doc-meta">{{ __('prt.receipt_no') }} <span class="ltr">{{ $payment->number ?: '#'.$payment->id }}</span></div>
        </td>
    </tr>
</table>

<hr class="rule">

<table class="meta">
    <tr>
        <td class="k">{{ __('prt.date') }}</td>
        <td class="v"><span class="ltr">{{ $payment->created_at->format('Y-m-d H:i') }}</span></td>
    </tr>
    <tr>
        <td class="k">{{ __('prt.subscriber') }}</td>
        <td class="v">{{ $subscriber->name }}</td>
    </tr>
    <tr>
        <td class="k">{{ __('prt.route') }}</td>
        <td class="v">{{ $subscriber->route?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">{{ __('prt.collector') }}</td>
        <td class="v">{{ $payment->collector?->name ?? '—' }}</td>
    </tr>
</table>

<div class="hero">
    <div class="lbl">{{ __('prt.amount_received') }}</div>
    <div class="val">{{ money($payment->amount) }}</div>
    <div class="lbl">{{ __('prt.method_cash') }}</div>
</div>

@if ($payment->allocations->isNotEmpty())
    <div class="section-title">{{ __('prt.allocation_title') }}</div>
    <table class="charges">
        <thead>
            <tr>
                <th style="width:60%">{{ __('prt.period') }}</th>
                <th class="num" style="width:40%">{{ __('prt.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payment->allocations as $allocation)
                <tr>
                    <td>{{ $allocation->bill ? ($isRtl ? period_label_ar($allocation->bill->period) : period_label($allocation->bill->period)) : '—' }}</td>
                    <td class="num">{{ money($allocation->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<table class="charges">
    <tfoot>
        <tr class="{{ $settled ? 'total' : 'grand' }}">
            <td>{{ $settled ? __('prt.settled') : __('prt.remaining_balance') }}</td>
            <td class="num"></td>
            <td class="num">{{ money($remaining) }}</td>
        </tr>
    </tfoot>
</table>

@if ($payment->note)
    <div class="note">{{ $payment->note }}</div>
@endif

<div class="thanks">{{ __('prt.thanks') }}</div>
