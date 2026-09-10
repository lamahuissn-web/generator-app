@php
    $isRtl = app()->isLocale('ar');
    $co = $co ?? \App\Services\PdfService::company();
    $t = $statement['totals'];
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
        <td class="k">{{ __('col.st_type') }}</td>
        <td class="v">{{ $subscriber->isMetered() ? __('gen.type_metered') : __('gen.type_flat') }}</td>
        <td class="k">{{ __('prt.issue_date') }}</td>
        <td class="v"><span class="ltr">{{ now()->format('Y-m-d') }}</span></td>
    </tr>
</table>

<table class="charges" style="margin-top:10px">
    <thead>
        <tr>
            <th class="num" style="width:14%">{{ __('col.st_date') }}</th>
            <th style="width:38%">{{ __('col.st_description') }}</th>
            <th class="num" style="width:10%">{{ __('col.st_ref') }}</th>
            <th class="num" style="width:12%">{{ __('col.st_debit') }}</th>
            <th class="num" style="width:12%">{{ __('col.st_credit') }}</th>
            <th class="num" style="width:14%">{{ __('col.st_balance') }}</th>
        </tr>
    </thead>
    <tbody>
        @if ($from && $t['opening'] != 0.0)
            <tr>
                <td class="num"><span class="ltr">{{ $from }}</span></td>
                <td>{{ __('col.st_opening') }}</td>
                <td class="num">—</td>
                <td class="num"></td>
                <td class="num"></td>
                <td class="num strong">{{ money($t['opening']) }}</td>
            </tr>
        @endif

        @forelse ($statement['rows'] as $row)
            <tr>
                <td class="num"><span class="ltr">{{ $row['date']?->format('Y-m-d') }}</span></td>
                <td>{{ $row['description'] }}</td>
                <td class="num"><span class="ltr">{{ $row['ref'] }}</span></td>
                <td class="num">@if ($row['due'] > 0){{ money($row['due']) }}@endif</td>
                <td class="num">@if ($row['settled'] > 0)<span class="ltr">− {{ money($row['settled']) }}</span>@endif</td>
                <td class="num strong">{{ money($row['balance']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6">{{ __('col.st_empty') }}</td>
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
        <tr class="grand">
            <td colspan="5">{{ __('col.st_closing') }}</td>
            <td class="num">{{ money($t['closing']) }}</td>
        </tr>
    </tfoot>
</table>

@if ($hidden = $statement['rows_total'] - $statement['rows_shown'])
    <div class="note">{{ __('col.st_hidden', ['count' => $hidden, 'from' => $from]) }}</div>
@endif

<div class="note">
    {{ $co['footer'] ?: __('prt.footer_note') }}
</div>

<div class="thanks">{{ __('prt.thanks') }}</div>
