@php
    $collector = $this->collector();
    $ledger = $this->ledger();
    $totals = $ledger['totals'] ?? null;
    $inHand = $this->inHand();
    $handedInRange = $this->handedInRange();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if (! $collector)
        <div class="gx-table-wrap">
            <p class="gx-empty">{{ __('col.led_pick') }}</p>
        </div>
    @else
        <p class="gx-strip">
            {{ __('col.led_movements', ['count' => $ledger['rows_shown']]) }}
            <span class="gx-chip gx-chip-dark">{{ $this->periodLabel() }}</span>
            @if ($this->allTime)
                <span class="gx-chip gx-chip-warn">{{ __('col.st_period_all') }}</span>
            @endif
        </p>

        <div class="gx-cards">
            <div class="gx-card">
                <p class="gx-card-label">{{ __('col.collected') }}</p>
                <p class="gx-stat-value gx-pos">{{ money($totals['debit']) }}</p>
                <p class="gx-stat-sub">{{ __('col.led_collected_sub') }}</p>
            </div>
            <div class="gx-card">
                <p class="gx-card-label">{{ __('col.handed') }}</p>
                <p class="gx-stat-value">{{ money($totals['credit']) }}</p>
                <p class="gx-stat-sub">{{ __('col.led_handed_sub') }}</p>
            </div>
            <div class="gx-card">
                <p class="gx-card-label">{{ __('col.st_opening') }}</p>
                <p class="gx-stat-value">{{ money(round($totals['opening'], 2)) }}</p>
                <p class="gx-stat-sub">{{ __('col.led_opening_sub') }}</p>
            </div>
            <div class="gx-card {{ $inHand > 0 ? 'gx-neg-bg' : 'gx-pos-bg' }}">
                <p class="gx-card-label">{{ __('col.in_hand') }}</p>
                <p class="gx-stat-value {{ $inHand > 0 ? 'gx-neg' : 'gx-pos' }}">{{ money($inHand) }}</p>
                <p class="gx-stat-sub">{{ __('col.led_in_hand_sub', ['amount' => money($handedInRange)]) }}</p>
            </div>
        </div>

        <div class="gx-table-wrap" style="margin-top:14px">
            <table class="gx-table">
                <thead>
                    <tr>
                        <th>{{ __('col.st_date') }}</th>
                        <th>{{ __('col.st_description') }}</th>
                        <th>{{ __('col.st_ref') }}</th>
                        <th class="gx-num">{{ __('col.led_in') }}</th>
                        <th class="gx-num">{{ __('col.led_out') }}</th>
                        <th class="gx-num">{{ __('col.led_balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($totals['opening'] != 0.0)
                        <tr>
                            <td class="gx-cell-muted" dir="ltr">{{ $this->from()?->toDateString() ?? '—' }}</td>
                            <td class="gx-cell-muted">{{ __('col.st_opening') }}</td>
                            <td class="gx-cell-muted">—</td>
                            <td class="gx-num"></td>
                            <td class="gx-num"></td>
                            <td class="gx-num gx-text-strong">{{ money($totals['opening']) }}</td>
                        </tr>
                    @endif

                    @forelse ($ledger['rows'] as $row)
                        <tr>
                            <td class="gx-cell-muted" dir="ltr">{{ $row['label'] }}</td>
                            <td>
                                {{ $row['description'] }}
                                @if ($row['detail'])
                                    <span class="gx-cell-faint"> · {{ $row['detail'] }}</span>
                                @endif
                            </td>
                            <td class="gx-cell-faint" dir="ltr">{{ $row['ref'] }}</td>
                            <td class="gx-num gx-pos">@if ($row['due'] > 0){{ money($row['due']) }}@endif</td>
                            <td class="gx-num gx-text-red">@if ($row['settled'] > 0){{ money($row['settled']) }}@endif</td>
                            <td class="gx-num gx-text-strong">{{ money($row['balance']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="gx-empty">{{ __('col.led_empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($ledger['rows'])
                    <tfoot>
                        <tr>
                            <td colspan="3">{{ __('col.st_totals') }}</td>
                            <td class="gx-num">{{ money($totals['debit']) }}</td>
                            <td class="gx-num">{{ money($totals['credit']) }}</td>
                            <td class="gx-num">{{ money($totals['closing']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <p class="gx-strip" style="margin-top:10px">{{ __('col.led_note') }}</p>
    @endif
</x-filament-panels::page>
