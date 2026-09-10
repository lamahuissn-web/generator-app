@php
    $co = $co ?? \App\Services\PdfService::company();
    $t = $ledger['totals'];
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
            <div class="doc-title">{{ __('col.led_doc_title') }}</div>
            @if ($allTime)
                <div class="doc-meta">{{ __('col.st_period_all') }}</div>
            @else
                <div class="doc-meta">{{ __('col.from') }} <span class="ltr">{{ $from }}</span></div>
                <div class="doc-meta">{{ __('col.to') }} <span class="ltr">{{ $to }}</span></div>
            @endif
        </td>
    </tr>
</table>

<hr class="rule">

<table class="meta">
    <tr>
        <td class="k">{{ __('col.collector') }}</td>
        <td class="v">{{ $collector->name }}</td>
        <td class="k">{{ __('col.led_phone') }}</td>
        <td class="v"><span class="ltr">{{ $collector->phone ?: '—' }}</span></td>
    </tr>
    <tr>
        <td class="k">{{ __('col.period') }}</td>
        <td class="v">
            @if ($allTime)
                {{ $periodLabel }}
            @else
                <span class="ltr">{{ $periodLabel }}</span>
            @endif
        </td>
        <td class="k">{{ __('prt.issue_date') }}</td>
        <td class="v"><span class="ltr">{{ now()->format('Y-m-d H:i') }}</span></td>
    </tr>
</table>

<div class="section-title">{{ __('col.summary') }}</div>
<table class="charges" style="margin-top:2px">
    <thead>
        <tr>
            <th style="width:22%">{{ __('col.st_opening') }}</th>
            <th style="width:22%">{{ __('col.led_in') }}</th>
            <th style="width:22%">{{ __('col.led_out') }}</th>
            <th style="width:34%">{{ __('col.led_balance') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="num">{{ money($t['opening']) }}</td>
            <td class="num strong">{{ money($t['debit']) }}</td>
            <td class="num">{{ money($t['credit']) }}</td>
            <td class="num strong">{{ money($t['closing']) }}</td>
        </tr>
    </tbody>
</table>

<div class="section-title">{{ __('col.led_movements_title') }}</div>
<table class="charges" style="margin-top:2px">
    <thead>
        <tr>
            <th class="num" style="width:13%">{{ __('col.st_date') }}</th>
            <th style="width:41%">{{ __('col.st_description') }}</th>
            <th class="num" style="width:10%">{{ __('col.st_ref') }}</th>
            <th class="num" style="width:12%">{{ __('col.led_in') }}</th>
            <th class="num" style="width:12%">{{ __('col.led_out') }}</th>
            <th class="num" style="width:12%">{{ __('col.led_balance') }}</th>
        </tr>
    </thead>
    <tbody>
        @if ($t['opening'] != 0.0)
            <tr>
                <td class="num"><span class="ltr">{{ $from ?? '—' }}</span></td>
                <td>{{ __('col.st_opening') }}</td>
                <td class="num">—</td>
                <td class="num"></td>
                <td class="num"></td>
                <td class="num strong">{{ money($t['opening']) }}</td>
            </tr>
        @endif

        @forelse ($ledger['rows'] as $row)
            <tr>
                <td class="num"><span class="ltr">{{ $row['label'] }}</span></td>
                <td>
                    {{ $row['description'] }}
                    @if ($row['detail'])
                        <span class="muted"> · {{ $row['detail'] }}</span>
                    @endif
                </td>
                <td class="num"><span class="ltr">{{ $row['ref'] }}</span></td>
                <td class="num">@if ($row['due'] > 0){{ money($row['due']) }}@endif</td>
                <td class="num">@if ($row['settled'] > 0){{ money($row['settled']) }}@endif</td>
                <td class="num strong">{{ money($row['balance']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6">{{ __('col.led_empty') }}</td>
            </tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr class="total">
            <td colspan="3">{{ __('col.st_totals') }}</td>
            <td class="num">{{ money($t['debit']) }}</td>
            <td class="num">{{ money($t['credit']) }}</td>
            <td class="num">{{ money($t['closing']) }}</td>
        </tr>
    </tfoot>
</table>

@if ($hidden = $ledger['rows_total'] - $ledger['rows_shown'])
    <div class="note">{{ __('col.st_hidden', ['count' => $hidden, 'from' => $from]) }}</div>
@endif

<div class="note">{{ __('col.led_note') }}</div>

<div class="thanks">{{ __('prt.thanks') }}</div>
