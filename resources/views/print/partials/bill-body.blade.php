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
            <div class="doc-title">{{ $bill->isSpecial() ? __('prt.special_title') : __('prt.bill_title') }}</div>
            <div class="doc-meta">{{ $periodLabel }}</div>
            <div class="doc-meta">{{ __('prt.bill_no') }} <span class="ltr">{{ $bill->number ?: '#'.$bill->id }}</span></div>
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
    @unless ($bill->isSpecial())
        <tr>
            <td class="k">{{ __('prt.amperage') }}</td>
            <td class="v"><span class="ltr">{{ $subscriber->amperage ? $subscriber->amperage.' A' : '—' }}</span></td>
            <td class="k">{{ __('prt.issue_date') }}</td>
            <td class="v"><span class="ltr">{{ $bill->created_at?->format('Y-m-d') ?? now()->format('Y-m-d') }}</span></td>
        </tr>
    @else
        <tr>
            <td class="k">{{ __('prt.subject') }}</td>
            <td class="v" colspan="3">{{ $bill->title ?: __('spc.default_title') }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('prt.issue_date') }}</td>
            <td class="v"><span class="ltr">{{ $bill->created_at?->format('Y-m-d') ?? now()->format('Y-m-d') }}</span></td>
            <td class="k">{{ __('prt.issue_serial') }}</td>
            <td class="v"><span class="ltr">{{ $bill->number ?: '#'.$bill->id }}</span></td>
        </tr>
    @endunless
    @if ($bill->type === \App\Models\Subscriber::TYPE_METERED && $bill->reading)
        @php $reading = $bill->reading; @endphp
        @if ($reading->hasMeterSwap())
            {{-- The device changed mid-cycle: the split is what explains the bill. --}}
            <tr>
                <td class="k">{{ __('prt.meter_old_final') }}</td>
                <td class="v"><span class="ltr">{{ number_format((float) $reading->old_meter_reading, 0) }}</span></td>
                <td class="k">{{ __('prt.meter_new_start') }}</td>
                <td class="v"><span class="ltr">{{ number_format((float) $reading->new_meter_start, 0) }}</span></td>
            </tr>
        @elseif ($reading->is_estimated)
            {{-- The stored meter value is derived, not read: printing it invites
                 "my meter says something else". Show what was billed instead. --}}
            <tr>
                <td class="k">{{ __('prt.prev_reading') }}</td>
                <td class="v"><span class="ltr">{{ number_format((float) $reading->previous_reading, 0) }}</span></td>
                <td class="k">{{ __('prt.estimated_kwh_short') }}</td>
                <td class="v"><span class="ltr">{{ number_format((float) $reading->consumption, 1) }} {{ __('prt.kwh') }}</span></td>
            </tr>
        @else
            <tr>
                <td class="k">{{ __('prt.prev_reading') }}</td>
                <td class="v"><span class="ltr">{{ number_format((float) $reading->previous_reading, 0) }}</span></td>
                <td class="k">{{ __('prt.cur_reading') }}</td>
                <td class="v"><span class="ltr">{{ number_format((float) $reading->current_reading, 0) }}</span></td>
            </tr>
        @endif
    @endif
</table>

@if ($bill->reading?->hasMeterSwap())
    <div class="note">
        {{ __('prt.meter_swap_line') }}
        <div class="ltr-line">&#8206;{{ $bill->reading->old_meter_no ?: '—' }} <span class="sym">&#8594;</span> {{ $bill->reading->new_meter_no }}&#8206;</div>
    </div>
@elseif ($bill->reading?->is_estimated)
    <div class="note">
        <span class="badge badge-partial">{{ __('prt.estimated_badge') }}</span>
        @if ($bill->reading->estimate_reason)
            {{ __('prt.estimate_reason_label') }}: {{ $bill->reading->estimate_reason }}
        @endif
    </div>
@endif

<table class="charges">
    <thead>
        <tr>
            <th style="width:60%">{{ __('prt.item') }}</th>
            <th class="num" style="width:18%">{{ __('prt.qty') }}</th>
            <th class="num" style="width:22%">{{ __('prt.amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @if ($bill->isSpecial())
            @foreach ($bill->items as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ number_format((float) $line->quantity, 2) }} × {{ money($line->unit_price) }}</td>
                    <td class="num">{{ money($line->amount) }}</td>
                </tr>
            @endforeach
        @elseif ($bill->type === \App\Models\Subscriber::TYPE_METERED)
            @php
                // The tariff is printed only when multiplying it actually gives the amount on this
                // line. With the office's rounding the consumption line carries the rounding, and
                // "105 kWh × $0.25" printed beside $27.00 is a multiplication that does not hold:
                // the paper has to add up on its own terms.
                $listPrice = (float) ($bill->kwh_price ?? \App\Services\BillingService::kwhPrice($bill->period));
                $showsRate = round((float) $bill->consumption * $listPrice, 2) === round((float) $bill->usage_amount, 2);
            @endphp
            <tr>
                <td>
                    {{ __('prt.usage_short') }}
                    @if ($showsRate)
                        <span class="ltr">({{ number_format((float) $bill->consumption, 1) }} kWh × {{ money($listPrice) }})</span>
                    @endif
                    @if ($bill->reading && ! $bill->reading->is_estimated && ! $bill->reading->hasMeterSwap())
                        {{-- LRM-wrapped LTR block: inside RTL text bidi reorders the two numbers
                             and mirrors the arrow, so the span must live in its own LTR line. --}}
                        <div class="muted ltr-line">&#8206;{{ number_format((float) $bill->reading->previous_reading, 0) }} <span class="sym">&#8594;</span> {{ number_format((float) $bill->reading->current_reading, 0) }}&#8206;</div>
                    @elseif ($bill->reading?->is_estimated)
                        <div class="muted">{{ __('prt.estimated_short') }}</div>
                    @elseif ($bill->reading?->hasMeterSwap())
                        {{-- Two meters in one cycle: show the split, not a single range. --}}
                        <div class="muted ltr-line">&#8206;({{ number_format((float) $bill->reading->old_meter_reading, 0) }} − {{ number_format((float) $bill->reading->previous_reading, 0) }}) + ({{ number_format((float) $bill->reading->current_reading, 0) }} − {{ number_format((float) $bill->reading->new_meter_start, 0) }})&#8206;</div>
                    @endif
                </td>
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
        @if ((float) $bill->credit_applied > 0)
            {{-- His own money, spent on this invoice: named so the addition below still comes out. --}}
            <tr>
                <td>{{ __('prt.credit_from_before') }}</td>
                <td class="num"></td>
                <td class="num"><span class="ltr">− {{ money($bill->credit_applied) }}</span></td>
            </tr>
        @endif
        @if ((float) $bill->discount_amount > 0)
            {{-- The office's waiver on this invoice, with the reason in small print: the paper
                 still adds up (usage + fees − discount − credit = total) on its own terms. --}}
            <tr>
                <td>{{ __('prt.discount') }}
                    @if ($bill->discount_note)<span class="muted"> — {{ $bill->discount_note }}</span>@endif
                </td>
                <td class="num"></td>
                <td class="num"><span class="ltr">− {{ money($bill->discount_amount) }}</span></td>
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
                    <td>
                        @if ($unpaid->isSpecial())
                            {{ $unpaid->title ?: __('spc.default_title') }}
                            <span class="muted ltr">({{ $unpaid->number }})</span>
                        @else
                            {{ $isRtl ? period_label_ar($unpaid->period) : period_label($unpaid->period) }}
                        @endif
                    </td>
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

@if ($bill->isSpecial() && $bill->note)
    <div class="note">{{ $bill->note }}</div>
@endif

<div class="note">
    {{-- The configured footer is subscription wording ("pay the subscription at the
         collector's visit"), which is wrong on a part invoice: specials get their own. --}}
    @if ($bill->isSpecial())
        {{ __('prt.footer_note_special') }}
    @else
        {{ $co['footer'] ?: __('prt.footer_note') }}
    @endif
</div>

<div class="thanks">
    {{ __('prt.thanks') }}
</div>

<div class="status-stamp">
    <span class="badge badge-{{ $status }}">{{ $statusLabel }}</span>
</div>
