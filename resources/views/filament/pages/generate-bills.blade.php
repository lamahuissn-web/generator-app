@php
    $run = $this->run();
    $s = $run['summary'];
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
    </p>

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
                    <th class="gx-num">{{ __('gen.col_reading') }}</th>
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
                        <td class="gx-num gx-cell-muted">{{ $r['reading'] !== null ? number_format((float) $r['reading'], 0) : '—' }}</td>
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
