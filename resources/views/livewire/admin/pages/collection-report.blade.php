<div class="grid gap-4">
    <x-admin.filters :fields="$this->filterSchema()" :values="$filters" id-prefix="cr" />

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-admin.stat :label="__('col.collected')" :value="money($totals['collected'])" icon="ki-wallet" tone="success"
                      :hint="$periodLabel" />
        <x-admin.stat :label="__('col.handed')" :value="money($totals['handed'])" icon="ki-bank" />
        <x-admin.stat :label="__('col.net')" :value="money($totals['net'])" icon="ki-arrows-circle" />
        <x-admin.stat :label="__('col.payments_count')" :value="number_format($totals['payments_count'])"
                      icon="ki-receipt-square" />
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <button type="button" wire:click="export" class="kt-btn kt-btn-sm kt-btn-outline">
            <i class="ki-filled ki-file-down"></i>
            {{ __('col.export') }}
        </button>
        <a href="{{ route('print.collection-report', [...$exportParams, 'size' => 'a4']) }}" target="_blank" rel="noopener"
           class="kt-btn kt-btn-sm kt-btn-outline">
            <i class="ki-filled ki-printer"></i>
            {{ __('col.print') }}
        </a>
        <a href="{{ route('print.collection-report', [...$exportParams, 'size' => 'a4', 'format' => 'pdf']) }}"
           target="_blank" rel="noopener" class="kt-btn kt-btn-sm kt-btn-outline">
            <i class="ki-filled ki-document"></i>
            {{ __('col.pdf') }}
        </a>
    </div>

    {{-- By collector first: it answers "who has the money", which is the question behind
         most visits to this screen. Each name opens that collector's ledger. --}}
    <x-admin.table
        :title="__('col.by_collector')"
        :columns="[
            ['label' => __('col.collector')],
            ['label' => __('col.payments_count'), 'align' => 'end', 'on_mobile' => false],
            ['label' => __('col.collected'), 'align' => 'end'],
            ['label' => __('col.handed'), 'align' => 'end', 'on_mobile' => false],
            ['label' => __('col.in_hand'), 'align' => 'end'],
        ]"
        :row-count="$collectors->count()">
        @foreach ($collectors as $row)
            <tr wire:key="cr-collector-{{ $row['collector_id'] }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                <td class="px-4 py-3 text-sm align-middle">
                    <a href="{{ route('admin.collector-statement', array_filter([
                            'collector_id' => $row['collector_id'],
                            'from' => $exportParams['from'] ?? null,
                            'to' => $exportParams['to'] ?? null,
                        ])) }}"
                       class="font-medium text-mono transition hover:text-amber-700 dark:hover:text-amber-400">
                        {{ $row['name'] }}
                    </a>
                </td>
                <x-admin.cell align="end" :on-mobile="false">{{ number_format($row['payments_count']) }}</x-admin.cell>
                <x-admin.cell align="end" amount>{{ money($row['collected']) }}</x-admin.cell>
                <x-admin.cell align="end" amount :on-mobile="false">{{ money($row['handed']) }}</x-admin.cell>
                {{-- In hand is all-time, not the range: it is the cash still out there. --}}
                <x-admin.cell align="end" amount :tone="$row['in_hand'] > 0 ? 'warning' : 'success'">
                    {{ money($row['in_hand']) }}
                </x-admin.cell>
            </tr>
        @endforeach
    </x-admin.table>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-admin.table :title="__('col.by_route')" :columns="[
                ['label' => __('t.route')],
                ['label' => __('col.payments_count'), 'align' => 'end', 'on_mobile' => false],
                ['label' => __('t.amount'), 'align' => 'end'],
            ]" :row-count="$routeRows->count()">
            @foreach ($routeRows as $row)
                <tr wire:key="cr-route-{{ $row['route_id'] ?? 'none' }}" class="border-b border-border last:border-b-0">
                    <td class="px-4 py-3 text-sm align-middle"><span class="text-secondary-foreground">{{ $row['name'] }}</span></td>
                    <x-admin.cell align="end" :on-mobile="false">{{ number_format($row['payments_count']) }}</x-admin.cell>
                    <x-admin.cell align="end" amount>{{ money($row['collected']) }}</x-admin.cell>
                </tr>
            @endforeach
        </x-admin.table>

        <x-admin.table :title="__('col.by_day')" :columns="[
                ['label' => __('t.date')],
                ['label' => __('col.payments_count'), 'align' => 'end', 'on_mobile' => false],
                ['label' => __('t.amount'), 'align' => 'end'],
            ]" :row-count="$dayRows->count()">
            @foreach ($dayRows as $row)
                <tr wire:key="cr-day-{{ $row['day'] }}" class="border-b border-border last:border-b-0">
                    <td class="px-4 py-3 text-sm align-middle">
                        <span dir="ltr" class="text-secondary-foreground">{{ $row['day'] }}</span>
                    </td>
                    <x-admin.cell align="end" :on-mobile="false">{{ number_format($row['payments_count']) }}</x-admin.cell>
                    <x-admin.cell align="end" amount>{{ money($row['collected']) }}</x-admin.cell>
                </tr>
            @endforeach
        </x-admin.table>
    </div>
</div>
