@php
    $isRtl = app()->isLocale('ar');
    $co = $co ?? \App\Services\PdfService::company();
    $periodLabel = $isRtl ? period_label_ar($bill->period) : period_label($bill->period);
    $dueThisBill = max(0, (float) $bill->total - (float) $bill->paid_amount);
    $status = $bill->paymentStatus();
    $statusLabel = ['paid' => __('portal.paid'), 'partial' => __('portal.partial'), 'unpaid' => __('portal.unpaid')][$status] ?? $status;
@endphp

<table class="head">
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
            <div class="doc-title">{{ __('prt.bill_title') }}</div>
            <div class="doc-meta">{{ $periodLabel }}</div>
            <div class="doc-meta">{{ __('prt.bill_no') }} <span class="ltr">#{{ $bill->id }}</span></div>
        </td>
    </tr>
</table>

<hr class="rule">

<table class="meta">
    <tr>
        <td class="k">{{ __('prt.subscriber') }}</td>
        <td class="v">{{ $subscriber->name }}</td>
        <td class="k">{{ __('prt.route') }}</td>
        <td class="v">{{ $subscriber->route?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">{{ __('prt.phone') }}</td>
        <td class="v"><span class="ltr">{{ $subscriber->phone ?: '—' }}</span></td>
        <td class="k">{{ __('prt.meter') }}</td>
        <td class="v"><span class="ltr">{{ $subscriber->meter_no ?: '—' }}</span></td>
    </tr>
    <tr>
        <td class="k">{{ __('prt.amperage') }}</td>
        <td class="v"><span class="ltr">{{ $subscriber->amperage ? $subscriber->amperage.' A' : '—' }}</span></td>
        <td class="k">{{ __('prt.issue_date') }}</td>
        <td class="v"><span class="ltr">{{ now()->format('Y-m-d') }}</span></td>
    </tr>
    @if ($bill->type === \App\Models\Subscriber::TYPE_METERED && $bill->reading)
        <tr>
            <td class="k">{{ __('prt.prev_reading') }}</td>
            <td class="v"><span class="ltr">{{ number_format((float) $bill->reading->previous_reading, 0) }}</span></td>
            <td class="k">{{ __('prt.cur_reading') }}</td>
            <td class="v"><span class="ltr">{{ number_format((float) $bill->reading->current_reading, 0) }}</span></td>
        </tr>
    @endif
</table>

<table class="charges">
    <thead>
        <tr>
            <th style="width:60%">{{ __('prt.item') }}</th>
            <th class="num" style="width:18%">{{ __('prt.qty') }}</th>
            <th class="num" style="width:22%">{{ __('prt.amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @if ($bill->type === \App\Models\Subscriber::TYPE_METERED)
            <tr>
                <td>{{ __('prt.usage_short') }} <span class="ltr">({{ number_format((float) $bill->consumption, 1) }} kWh × {{ money($bill->kwh_price ?? \App\Services\BillingService::kwhPrice()) }})</span></td>
                <td class="num">{{ number_format((float) $bill->consumption, 1) }} {{ __('prt.kwh') }}</td>
                <td class="num">{{ money($bill->usage_amount) }}</td>
            </tr>
            @if ((float) $bill->maintenance_fee > 0)
                <tr>
                    <td>{{ __('prt.maintenance') }}@if ($subscriber->amperage) <span class="ltr">({{ $subscriber->amperage }}A)</span>@endif</td>
                    <td class="num">1</td>
                    <td class="num">{{ money($bill->maintenance_fee) }}</td>
                </tr>
            @endif
        @else
            <tr>
                <td>{{ __('prt.flat') }}</td>
                <td class="num">1</td>
                <td class="num">{{ money($bill->flat_amount) }}</td>
            </tr>
        @endif
    </tbody>
    <tfoot>
        @php
            $paid = (float) $bill->paid_amount;
            // Only worth an "owed periods" table when more than this one bill is unpaid.
            $showOwed = $unpaidBills->count() > 1
                || ($unpaidBills->count() === 1 && $unpaidBills->first()->id !== $bill->id);
        @endphp
        @if ($paid > 0 || $showOwed)
            <tr class="total">
                <td>{{ __('prt.bill_charges') }}</td>
                <td class="num"></td>
                <td class="num">{{ money($bill->charges()) }}</td>
            </tr>
        @endif
        @if ($paid > 0)
            <tr>
                <td>{{ __('prt.paid') }}</td>
                <td class="num"></td>
                <td class="num"><span class="ltr">− {{ money($paid) }}</span></td>
            </tr>
        @endif
        @unless ($showOwed)
            <tr class="grand">
                <td>{{ __('prt.due_now') }}</td>
                <td class="num"></td>
                <td class="num">{{ money($dueThisBill) }}</td>
            </tr>
        @endunless
    </tfoot>
</table>

@if ($showOwed)
    <div class="section-title">{{ __('prt.owed_title') }}</div>
    <table class="owed">
        <thead>
            <tr>
                <th style="width:45%">{{ __('prt.period') }}</th>
                <th class="num" style="width:27%">{{ __('prt.bill_amount') }}</th>
                <th class="num" style="width:28%">{{ __('prt.remaining') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($unpaidBills as $unpaid)
                <tr>
                    <td>{{ $isRtl ? period_label_ar($unpaid->period) : period_label($unpaid->period) }}</td>
                    <td class="num">{{ money($unpaid->total) }}</td>
                    <td class="num">{{ money($unpaid->unpaidAmount()) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="strong">{{ __('prt.owed_total') }}</td>
                <td class="num strong">{{ money($unpaidBills->sum(fn ($b) => $b->unpaidAmount())) }}</td>
            </tr>
        </tfoot>
    </table>
@endif

<div class="note">
    {{ $co['footer'] ?: __('prt.footer_note') }}
</div>

<div class="thanks">
    {{ __('prt.thanks') }}
</div>

<div class="status-stamp">
    <span class="badge badge-{{ $status }}">{{ $statusLabel }}</span>
</div>
