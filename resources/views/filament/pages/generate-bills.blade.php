@php
    $run = $this->run();
    $s = $run['summary'];
    $tariff = $this->tariff();
    $progress = $this->progress();
    $toCreate = $s[\App\Services\BillingService::RUN_CREATE];
    $rows = $run['rows'];
    $invoicedTotal = array_sum(array_map(
        fn ($r) => in_array($r['state'], [\App\Services\BillingService::RUN_CREATE, \App\Services\BillingService::RUN_EXISTS], true) ? $r['total'] : 0,
        $rows,
    ));
    $stateLabels = [
        'create' => __('gen.state_create'),
        'exists' => __('gen.state_exists'),
        'no_reading' => __('gen.state_no_reading'),
        'inactive' => __('gen.state_inactive'),
        'zero' => __('gen.state_zero'),
    ];
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <p class="gx-strip">
        {{ __('gen.preview_strip', ['count' => count($rows)]) }}
        <span class="gx-chip">{{ __('gen.chip_create', ['count' => $toCreate]) }}</span>
        <span class="gx-chip">{{ __('gen.chip_exists', ['count' => $s[\App\Services\BillingService::RUN_EXISTS]]) }}</span>
        <span class="gx-chip gx-chip-warn">{{ __('gen.chip_skipped', ['count' => $s[\App\Services\BillingService::RUN_NO_READING] + $s[\App\Services\BillingService::RUN_INACTIVE] + $s[\App\Services\BillingService::RUN_ZERO]]) }}</span>
        <span class="gx-chip gx-chip-dark">{{ __('gen.chip_total', ['amount' => money($invoicedTotal)]) }}</span>
        <span class="gx-chip {{ $tariff['from_tariff'] ? 'gx-chip-warn' : '' }}">
            {{ __('gen.chip_tariff', ['price' => money($tariff['price']), 'source' => $tariff['from_tariff'] ? __('gen.tariff_cycle') : __('gen.tariff_global')]) }}
        </span>
    </p>

    {{-- Metering coverage of the cycle: the "all readings in?" gate --}}
    <div class="gx-cover">
        <div class="gx-cover-head">
            <span class="gx-cover-title">{{ __('gen.coverage_title') }}</span>
            <span class="gx-chip {{ $progress['pending'] > 0 ? 'gx-chip-warn' : 'gx-chip-ok' }}">
                {{ $progress['pending'] > 0
                    ? __('gen.coverage_pending', ['count' => $progress['pending']])
                    : __('gen.coverage_done') }}
            </span>
            <span class="gx-cell-muted">{{ __('gen.coverage_counts', ['recorded' => $progress['recorded'], 'total' => $progress['total']]) }}</span>
        </div>

        @if ($progress['routes'])
            <div class="gx-cover-routes">
                @foreach ($progress['routes'] as $route)
                    @php($pct = $route['total'] > 0 ? round($route['recorded'] / $route['total'] * 100) : 0)
                    <div class="gx-cover-route">
                        <span class="gx-cover-name">{{ $route['name'] }}</span>
                        <span class="gx-cover-bar">
                            <span class="gx-cover-fill {{ $route['pending'] > 0 ? 'gx-cover-fill-warn' : '' }}" style="width: {{ $pct }}%"></span>
                        </span>
                        <span class="gx-cover-nums">{{ $route['recorded'] }}/{{ $route['total'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($progress['pending'] > 0)
            <p class="gx-cover-note">{{ __('gen.coverage_note') }}</p>
        @endif
    </div>

    @if ($lastRun && $lastRun['created'] > 0)
        <div class="gx-panel-ok">
            <div>
                <div class="gx-panel-ok-title">{{ __('gen.done_title', ['count' => $lastRun['created'], 'period' => app()->isLocale('ar') ? period_label_ar($lastRun['period']) : period_label($lastRun['period'])]) }}</div>
                <div class="gx-panel-ok-sub">{{ __('gen.done_sub', ['amount' => money($lastRun['invoiced'])]) }}</div>
            </div>
            <div class="gx-panel-ok-actions">
                <a class="gx-btn gx-btn-primary" href="{{ route('print.bills', ['ids' => implode(',', $lastRun['bill_ids']), 'size' => 'a5']) }}" target="_blank" rel="noopener">{{ __('gen.print_run') }}</a>
                <a class="gx-btn" href="{{ route('print.bills', ['ids' => implode(',', $lastRun['bill_ids']), 'size' => 'a5', 'format' => 'pdf']) }}" target="_blank" rel="noopener">{{ __('gen.pdf_run') }}</a>
                <a class="gx-btn" href="{{ route('print.bills', ['ids' => implode(',', $lastRun['bill_ids']), 'size' => 'a5', 'format' => 'zip']) }}">{{ __('gen.zip_run') }}</a>
            </div>
        </div>
    @endif

    <div class="gx-table-wrap">
        <table class="gx-table">
            <thead>
                <tr>
                    <th>{{ __('gen.col_subscriber') }}</th>
                    <th>{{ __('gen.col_route') }}</th>
                    <th>{{ __('gen.col_type') }}</th>
                    <th class="gx-num">{{ __('gen.col_reading_span') }}</th>
                    <th class="gx-num">{{ __('gen.col_consumption') }}</th>
                    <th class="gx-num">{{ __('gen.col_usage') }}</th>
                    <th class="gx-num">{{ __('gen.col_maintenance') }}</th>
                    <th class="gx-num">{{ __('gen.col_flat') }}</th>
                    <th class="gx-num">{{ __('gen.col_prev_debt') }}</th>
                    <th class="gx-num">{{ __('gen.col_total') }}</th>
                    <th>{{ __('gen.col_state') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td>
                            <a class="gx-link" href="{{ \App\Filament\Resources\SubscriberResource::getUrl('view', ['record' => $r['subscriber_id']]) }}" target="_blank" rel="noopener">{{ $r['name'] }}</a>
                            @if ($r['meter_no'])
                                <span class="gx-cell-faint"> · <span dir="ltr">{{ $r['meter_no'] }}</span></span>
                            @endif
                        </td>
                        <td class="gx-cell-muted">{{ $r['route'] ?? '—' }}</td>
                        <td class="gx-cell-muted">{{ $r['type'] === 'metered' ? __('gen.type_metered') : __('gen.type_flat') }}</td>
                        <td class="gx-num gx-cell-muted">
                            @if ($r['reading_prev'] !== null && $r['reading'] !== null)
                                <span class="gx-ltr">{{ number_format((float) $r['reading_prev'], 0) }} → {{ number_format((float) $r['reading'], 0) }}</span>
                            @elseif ($r['reading'] !== null)
                                <span class="gx-ltr">{{ number_format((float) $r['reading'], 0) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="gx-num gx-cell-muted">{{ $r['consumption'] !== null ? number_format((float) $r['consumption'], 1) : '—' }}</td>
                        <td class="gx-num">{{ money($r['usage']) }}</td>
                        <td class="gx-num">{{ money($r['maintenance']) }}</td>
                        <td class="gx-num">{{ money($r['flat']) }}</td>
                        <td class="gx-num {{ (float) $r['prev_debt'] > 0 ? 'gx-text-red' : 'gx-cell-faint' }}">{{ money($r['prev_debt']) }}</td>
                        <td class="gx-num gx-text-strong">{{ money($r['total']) }}</td>
                        <td><span class="gx-badge gx-badge-{{ $r['state'] }}">{{ $stateLabels[$r['state']] ?? $r['state'] }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="gx-empty">{{ __('gen.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($rows)
                <tfoot>
                    <tr>
                        <td colspan="9">{{ __('gen.foot_total') }}</td>
                        <td class="gx-num">{{ money($invoicedTotal) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</x-filament-panels::page>
