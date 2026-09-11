@php
    // Alert -> chip tone and icon, in one place: the row chip and the CSV label read the same
    // ranking, so a new alert cannot be coloured one way on screen and described another way
    // in the export.
    $alertChip = [
        \App\Reports\ReadingBoard::ALERT_BROKEN => ['tone' => 'kt-badge-warning kt-badge-outline', 'icon' => 'ki-information'],
        \App\Reports\ReadingBoard::ALERT_NEVER => ['tone' => 'kt-badge-destructive', 'icon' => 'ki-time'],
        \App\Reports\ReadingBoard::ALERT_STALE => ['tone' => 'kt-badge-destructive kt-badge-outline', 'icon' => 'ki-time'],
        \App\Reports\ReadingBoard::ALERT_PENDING => ['tone' => 'kt-badge-outline', 'icon' => 'ki-bill'],
        \App\Reports\ReadingBoard::ALERT_SUSPECT => ['tone' => 'kt-badge-warning', 'icon' => 'ki-information'],
        \App\Reports\ReadingBoard::ALERT_OK => ['tone' => 'kt-badge-success kt-badge-outline', 'icon' => 'ki-check'],
    ];
@endphp

<div class="grid gap-4">
    <x-admin.filters :fields="$this->filterSchema()" :values="$filters" id-prefix="rb" :reset="false" />

    {{-- The four figures an office asks about a cycle, in the order it asks them: is it
         covered, how much is left, who has been missed for months, and what is stuck. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-admin.stat
            :label="__('gen.coverage')"
            :value="ltr_isolate(number_format($summary['recorded']).' / '.number_format($summary['total']))"
            icon="ki-bill"
            :tone="$summary['pending'] > 0 ? 'warning' : 'success'"
            :hint="$summary['pending'] > 0
                ? __('gen.pending_warning', ['count' => $summary['pending']])
                : __('gen.coverage_ok')" />

        <x-admin.stat
            :label="__('rdgb.stale', ['n' => \App\Reports\ReadingBoard::STALE_AFTER])"
            :value="number_format($summary['stale'])"
            icon="ki-time"
            :tone="$summary['stale'] > 0 ? 'danger' : 'success'"
            :hint="__('rdgb.stale_hint')" />

        <x-admin.stat
            :label="__('rdgb.broken')"
            :value="number_format($summary['broken'])"
            icon="ki-information-2"
            :tone="$summary['broken'] > 0 ? 'warning' : 'success'"
            :hint="__('rdgb.broken_hint')" />

        <x-admin.stat
            :label="__('rdgb.suspect')"
            :value="number_format($summary['suspect'])"
            icon="ki-information"
            :tone="$summary['suspect'] > 0 ? 'warning' : 'success'"
            :hint="__('rdgb.suspect_hint')" />
    </div>

    @if ($summary['broken'] > 0)
        {{-- The count is only useful if the decision is one click away: a dead meter is the one
             alert on this board that no reading can clear. --}}
        <a href="{{ route('admin.meter-cases') }}" class="kt-btn kt-btn-lg justify-center">
            <i class="ki-filled ki-shield-cross"></i>
            {{ __('rdgb.open_cases', ['count' => $summary['broken']]) }}
        </a>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <x-admin.table
            :title="__('rdgb.by_route')"
            :description="__('rdgb.by_route_hint')"
            :columns="[
                ['label' => __('t.route')],
                ['label' => __('gen.coverage'), 'align' => 'end'],
                ['label' => __('rdgb.chip_pending'), 'align' => 'end'],
            ]"
            :row-count="count($coverage['routes'])"
            :empty="__('t.no_results')">
            @foreach ($coverage['routes'] as $route)
                <tr wire:key="rb-route-{{ $route['id'] ?? 'none' }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                    <td class="px-4 py-3 text-sm align-middle">
                        <span class="font-medium text-mono">{{ $route['name'] }}</span>
                    </td>
                    <x-admin.cell align="end">
                        {{ __('rdgb.of', ['done' => number_format($route['recorded']), 'total' => number_format($route['total'])]) }}
                    </x-admin.cell>
                    <td class="px-4 py-3 text-end text-sm align-middle">
                        <span @class([
                            'kt-badge kt-badge-sm',
                            'kt-badge-destructive' => $route['pending'] > 0,
                            'kt-badge-success kt-badge-outline' => $route['pending'] === 0,
                        ])>{{ number_format($route['pending']) }}</span>
                    </td>
                </tr>
            @endforeach
        </x-admin.table>

        <x-admin.table
            :title="__('rdgb.by_collector')"
            :description="__('rdgb.by_collector_hint')"
            :columns="[
                ['label' => __('t.entered_by')],
                ['label' => __('mdl.readings'), 'align' => 'end'],
            ]"
            :row-count="$byCollector->count()"
            :empty="__('t.no_results')">
            @foreach ($byCollector as $collector)
                <tr wire:key="rb-collector-{{ $loop->index }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                    <td class="px-4 py-3 text-sm align-middle">
                        <span class="font-medium text-mono">{{ $collector['name'] }}</span>
                        @if ($collector['phone'])
                            <div class="mt-0.5 text-xs text-muted-foreground" dir="ltr">{{ $collector['phone'] }}</div>
                        @endif
                    </td>
                    <x-admin.cell align="end">
                        {{ plural('rdgb.readings_count', $collector['count']) }}
                        @if ($collector['estimated'] > 0)
                            <span class="text-xs text-muted-foreground">{{ __('rdgb.estimated_count', ['n' => $collector['estimated']]) }}</span>
                        @endif
                    </x-admin.cell>
                </tr>
            @endforeach
        </x-admin.table>
    </div>

    <x-admin.table
        :title="__('rdgb.list_title')"
        :description="__('rdgb.list_hint')"
        :columns="[
            ['label' => __('t.subscriber')],
            ['label' => __('t.route'), 'on_mobile' => false],
            ['label' => __('t.reading_span'), 'align' => 'end', 'on_mobile' => false],
            ['label' => __('rdgb.last_reading'), 'on_mobile' => false],
            ['label' => __('t.kwh'), 'align' => 'end'],
            ['label' => __('t.status'), 'align' => 'end'],
        ]"
        :row-count="$rows->count()"
        :empty="__('rdgb.none')">
        <x-slot:actions>
            {{-- The office works this list by phone, so the export is the point of the screen
                 for them; the note beside it says where a reading is actually recorded. --}}
            <button type="button" wire:click="export" class="kt-btn kt-btn-sm kt-btn-outline">
                <i class="ki-filled ki-file-down"></i>
                {{ __('over.export_btn') }}
            </button>
        </x-slot:actions>

        @foreach ($rows as $row)
            @php $chip = $alertChip[$row['alert']] ?? $alertChip[\App\Reports\ReadingBoard::ALERT_OK]; @endphp

            <tr wire:key="rb-row-{{ $row['id'] }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                <td class="px-4 py-3 text-sm align-middle">
                    <a href="{{ route('admin.subscribers.show', $row['id']) }}"
                       class="font-medium text-mono transition hover:text-amber-700 dark:hover:text-amber-400">
                        {{ $row['name'] }}
                    </a>
                    @if ($row['phone'])
                        <div class="mt-0.5 text-xs text-muted-foreground" dir="ltr">{{ $row['phone'] }}</div>
                    @endif
                    @if ($row['meter_no'])
                        <div class="mt-0.5 text-xs text-muted-foreground">
                            {{ __('t.meter') }} {{ ltr_isolate($row['meter_no']) }}
                        </div>
                    @endif
                </td>

                <x-admin.cell :on-mobile="false">{{ $row['route'] ?? '—' }}</x-admin.cell>

                {{-- The span, when there is one: it is what makes a wrong read visible without
                     opening the subscriber. --}}
                <x-admin.cell align="end" :on-mobile="false">
                    @if ($row['recorded'])
                        <span dir="ltr" class="amount-cell">
                            {{ number_format((float) $row['previous'], 0) }} → {{ number_format((float) $row['current'], 0) }}
                        </span>
                    @else
                        —
                    @endif
                </x-admin.cell>

                {{-- The column this screen exists for: when this house was last read, and how
                     long ago that is. --}}
                <x-admin.cell :on-mobile="false">
                    @if ($row['last_period'] === null)
                        <span class="text-destructive">{{ __('rdgb.never_read') }}</span>
                    @else
                        {{ period_label_i18n($row['last_period']) }}
                        <span class="text-xs text-muted-foreground">
                            {{ $row['gap'] === 0 ? __('rdgb.this_cycle') : plural('rdgb.months_ago', (int) $row['gap']) }}
                        </span>
                    @endif
                </x-admin.cell>

                <x-admin.cell align="end">
                    {{ $row['consumption'] === null ? '—' : number_format((float) $row['consumption'], 1) }}
                </x-admin.cell>

                <td class="px-4 py-3 text-end text-sm align-middle">
                    <span class="kt-badge kt-badge-sm {{ $chip['tone'] }}">
                        <i class="ki-filled {{ $chip['icon'] }}"></i>
                        {{ $report->alertLabel($row) }}
                    </span>
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    <p class="px-1 text-2xs text-muted-foreground">{{ __('rdgb.read_only') }}</p>
</div>
