@php
    $co = $co ?? \App\Services\PdfService::company();
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
            <div class="doc-title">{{ __('col.title') }}</div>
            <div class="doc-meta">{{ __('col.from') }} <span class="ltr">{{ $from }}</span></div>
            <div class="doc-meta">{{ __('col.to') }} <span class="ltr">{{ $to }}</span></div>
        </td>
    </tr>
</table>

<hr class="rule">

<table class="meta">
    <tr>
        <td class="k">{{ __('col.period') }}</td>
        <td class="v"><span class="ltr">{{ $from }}</span> — <span class="ltr">{{ $to }}</span></td>
        <td class="k">{{ __('col.route') }}</td>
        <td class="v">{{ $routeName ?: __('gen.all_routes') }}</td>
    </tr>
    <tr>
        <td class="k">{{ __('col.collector') }}</td>
        <td class="v">{{ $collectorName ?: __('col.all_collectors') }}</td>
        <td class="k">{{ __('prt.issue_date') }}</td>
        <td class="v"><span class="ltr">{{ now()->format('Y-m-d H:i') }}</span></td>
    </tr>
</table>

<div class="section-title">{{ __('col.summary') }}</div>
<table class="charges" style="margin-top:2px">
    <thead>
        <tr>
            <th style="width:34%">{{ __('col.collected') }}</th>
            <th style="width:22%">{{ __('col.payments_count') }}</th>
            <th style="width:22%">{{ __('col.handed') }}</th>
            <th style="width:22%">{{ __('col.in_hand_total') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="strong">{{ money($totals['collected']) }}</td>
            <td class="num">{{ $totals['payments_count'] }}</td>
            <td class="num">{{ money($totals['handed']) }}</td>
            <td class="num strong">{{ money($totals['in_hand']) }}</td>
        </tr>
    </tbody>
</table>

<div class="section-title">{{ __('col.by_collector') }}</div>
<table class="charges">
    <thead>
        <tr>
            <th style="width:34%">{{ __('col.collector') }}</th>
            <th class="num" style="width:18%">{{ __('col.payments_count') }}</th>
            <th class="num" style="width:20%">{{ __('col.collected') }}</th>
            <th class="num" style="width:14%">{{ __('col.handed') }}</th>
            <th class="num" style="width:14%">{{ __('col.in_hand') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($byCollector as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $row['payments_count'] }}</td>
                <td class="num">{{ money($row['collected']) }}</td>
                <td class="num">{{ money($row['handed']) }}</td>
                <td class="num strong">{{ money($row['in_hand']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="5">{{ __('col.empty') }}</td>
            </tr>
        @endforelse
    </tbody>
    @if (count($byCollector))
        <tfoot>
            <tr class="total">
                <td>{{ __('col.grand') }}</td>
                <td class="num">{{ array_sum(array_column($byCollector, 'payments_count')) }}</td>
                <td class="num">{{ money(array_sum(array_column($byCollector, 'collected'))) }}</td>
                <td class="num">{{ money(array_sum(array_column($byCollector, 'handed'))) }}</td>
                <td class="num">{{ money(array_sum(array_column($byCollector, 'in_hand'))) }}</td>
            </tr>
        </tfoot>
    @endif
</table>

<div class="section-title">{{ __('col.by_route') }}</div>
<table class="charges" style="margin-top:2px">
    <thead>
        <tr>
            <th style="width:52%">{{ __('col.route') }}</th>
            <th class="num" style="width:18%">{{ __('col.payments_count') }}</th>
            <th class="num" style="width:30%">{{ __('t.amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($byRoute as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $row['payments_count'] }}</td>
                <td class="num">{{ money($row['collected']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="3">{{ __('col.empty') }}</td>
            </tr>
        @endforelse
    </tbody>
    @if (count($byRoute))
        <tfoot>
            <tr class="total">
                <td>{{ __('col.grand') }}</td>
                <td class="num">{{ array_sum(array_column($byRoute, 'payments_count')) }}</td>
                <td class="num">{{ money(array_sum(array_column($byRoute, 'collected'))) }}</td>
            </tr>
        </tfoot>
    @endif
</table>

<div class="section-title">{{ __('col.by_day') }}</div>
<table class="charges" style="margin-top:2px">
    <thead>
        <tr>
            <th style="width:48%">{{ __('t.date') }}</th>
            <th class="num" style="width:20%">{{ __('col.payments_count') }}</th>
            <th class="num" style="width:32%">{{ __('t.amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($byDay as $row)
            <tr>
                <td><span class="ltr">{{ $row['day'] }}</span></td>
                <td class="num">{{ $row['payments_count'] }}</td>
                <td class="num">{{ money($row['collected']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="3">{{ __('col.empty') }}</td>
            </tr>
        @endforelse
    </tbody>
    @if (count($byDay))
        <tfoot>
            <tr class="total">
                <td>{{ __('col.grand') }}</td>
                <td class="num">{{ array_sum(array_column($byDay, 'payments_count')) }}</td>
                <td class="num">{{ money(array_sum(array_column($byDay, 'collected'))) }}</td>
            </tr>
        </tfoot>
    @endif
</table>

<div class="note">
    {{ __('col.in_hand_note') }}
</div>
