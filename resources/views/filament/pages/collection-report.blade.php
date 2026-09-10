@php
    $totals = $this->totals();
    $byCollector = $this->collectors();
    $byRoute = $this->routeRows();
    $byDay = $this->dayRows();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <p class="gx-strip">
        {{ __('col.showing') }}
        <span class="gx-chip gx-chip-dark">{{ $this->periodLabel() }}</span>
        @if ($this->routeId())
            <span class="gx-chip">{{ __('col.route') }}: {{ \App\Models\Route::whereKey($this->routeId())->value('name') }}</span>
        @endif
        @if ($this->collectorId())
            <span class="gx-chip">{{ __('col.collector') }}: {{ \App\Models\User::whereKey($this->collectorId())->value('name') }}</span>
        @endif
    </p>

    <div class="gx-cards">
        <div class="gx-card">
            <p class="gx-card-label">{{ __('col.collected') }}</p>
            <p class="gx-stat-value gx-pos">{{ money($totals['collected']) }}</p>
            <p class="gx-stat-sub">{{ __('col.payments_count') }}: {{ $totals['payments_count'] }}</p>
        </div>
        <div class="gx-card">
            <p class="gx-card-label">{{ __('col.handed') }}</p>
            <p class="gx-stat-value">{{ money($totals['handed']) }}</p>
            <p class="gx-stat-sub">{{ __('col.handed_hint') }}</p>
        </div>
        <div class="gx-card {{ $totals['net'] > 0 ? 'gx-neg-bg' : 'gx-pos-bg' }}">
            <p class="gx-card-label">{{ __('col.net_range') }}</p>
            <p class="gx-stat-value {{ $totals['net'] > 0 ? 'gx-neg' : 'gx-pos' }}">{{ money($totals['net']) }}</p>
            <p class="gx-stat-sub">{{ __('col.net_range_hint') }}</p>
        </div>
        <div class="gx-card {{ $totals['in_hand'] > 0 ? 'gx-neg-bg' : 'gx-pos-bg' }}">
            <p class="gx-card-label">{{ __('col.in_hand_total') }}</p>
            <p class="gx-stat-value {{ $totals['in_hand'] > 0 ? 'gx-neg' : 'gx-pos' }}">{{ money($totals['in_hand']) }}</p>
            <p class="gx-stat-sub">{{ __('col.in_hand_total_hint') }}</p>
        </div>
    </div>

    {{-- Per collector: the reconciliation table --}}
    <div class="gx-table-wrap" style="margin-top:14px">
        <table class="gx-table">
            <thead>
                <tr>
                    <th>{{ __('col.collector') }}</th>
                    <th class="gx-num">{{ __('col.payments_count') }}</th>
                    <th class="gx-num">{{ __('col.collected') }}</th>
                    <th class="gx-num">{{ __('col.handed') }}</th>
                    <th class="gx-num">{{ __('col.in_hand') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($byCollector as $row)
                    <tr>
                        <td>
                            <a class="gx-link" href="{{ \App\Filament\Pages\CollectorStatement::getUrl([
                                'collector_id' => $row['collector_id'],
                                'from' => $this->from()->toDateString(),
                                'to' => $this->to()->toDateString(),
                            ]) }}">
                                {{ $row['name'] }}
                            </a>
                            <span class="gx-cell-faint"> ↗</span>
                        </td>
                        <td class="gx-num gx-cell-muted">{{ $row['payments_count'] }}</td>
                        <td class="gx-num gx-text-strong">{{ money($row['collected']) }}</td>
                        <td class="gx-num gx-cell-muted">{{ money($row['handed']) }}</td>
                        <td class="gx-num {{ $row['in_hand'] > 0 ? 'gx-text-red' : 'gx-pos' }}">{{ money($row['in_hand']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="gx-empty">{{ __('col.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($byCollector->isNotEmpty())
                <tfoot>
                    <tr>
                        <td>{{ __('col.grand') }}</td>
                        <td class="gx-num">{{ $byCollector->sum('payments_count') }}</td>
                        <td class="gx-num">{{ money($byCollector->sum('collected')) }}</td>
                        <td class="gx-num">{{ money($byCollector->sum('handed')) }}</td>
                        <td class="gx-num">{{ money($byCollector->sum('in_hand')) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="gx-two-col" style="margin-top:14px">
        <div class="gx-table-wrap">
            <table class="gx-table">
                <thead>
                    <tr>
                        <th colspan="3">{{ __('col.by_route') }}</th>
                    </tr>
                    <tr>
                        <th>{{ __('col.route') }}</th>
                        <th class="gx-num">{{ __('col.payments_count') }}</th>
                        <th class="gx-num">{{ __('t.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byRoute as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="gx-num gx-cell-muted">{{ $row['payments_count'] }}</td>
                            <td class="gx-num gx-text-strong">{{ money($row['collected']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="gx-empty">{{ __('col.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($byRoute->isNotEmpty())
                    <tfoot>
                        <tr>
                            <td>{{ __('col.grand') }}</td>
                            <td class="gx-num">{{ $byRoute->sum('payments_count') }}</td>
                            <td class="gx-num">{{ money($byRoute->sum('collected')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="gx-table-wrap">
            <table class="gx-table">
                <thead>
                    <tr>
                        <th colspan="3">{{ __('col.by_day') }}</th>
                    </tr>
                    <tr>
                        <th>{{ __('t.date') }}</th>
                        <th class="gx-num">{{ __('col.payments_count') }}</th>
                        <th class="gx-num">{{ __('t.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byDay as $row)
                        <tr>
                            <td class="gx-cell-muted" dir="ltr">{{ $row['day'] }}</td>
                            <td class="gx-num gx-cell-muted">{{ $row['payments_count'] }}</td>
                            <td class="gx-num gx-text-strong">{{ money($row['collected']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="gx-empty">{{ __('col.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($byDay->isNotEmpty())
                    <tfoot>
                        <tr>
                            <td>{{ __('col.grand') }}</td>
                            <td class="gx-num">{{ $byDay->sum('payments_count') }}</td>
                            <td class="gx-num">{{ money($byDay->sum('collected')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</x-filament-panels::page>
