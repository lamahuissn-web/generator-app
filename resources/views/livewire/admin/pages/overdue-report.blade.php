@php
    // Bucket -> tone and label, in one place: the row colour, the badge and the summary all
    // read the same map, so a new bucket cannot be coloured one way and labelled another.
    $bucketMeta = [
        'red' => ['tone' => 'danger', 'label' => __('over.opt_red')],
        'yellow' => ['tone' => 'warning', 'label' => __('over.opt_yellow')],
        'current' => ['tone' => 'info', 'label' => __('over.opt_current')],
        'settled' => ['tone' => 'default', 'label' => __('over.opt_settled')],
    ];
@endphp

<div class="grid gap-4">
    <x-admin.filters :fields="$this->filterSchema()" :values="$filters" id-prefix="ov" />

    <div class="grid gap-4 sm:grid-cols-3">
        <x-admin.stat :label="__('over.count')" :value="number_format($summary['count'])" icon="ki-users"
                      :hint="__('over.count_subs')" />
        <x-admin.stat :label="__('over.opt_red')" :value="money($summary['red'])" icon="ki-information-2" tone="danger"
                      :hint="__('over.opt_red')" />
        <x-admin.stat :label="__('t.total_due')" :value="money($summary['total'])" icon="ki-dollar" tone="warning" />
    </div>

    <div class="flex justify-end">
        {{-- CSV, not PDF: this list is worked through — called, paid, ticked off — and a
             spreadsheet is the tool for that. --}}
        <button type="button" wire:click="export" class="kt-btn kt-btn-sm kt-btn-outline">
            <i class="ki-filled ki-file-down"></i>
            {{ __('over.export_btn') }}
        </button>
    </div>

    <x-admin.table
        :columns="[
            ['label' => __('t.name')],
            ['label' => __('t.route'), 'on_mobile' => false],
            ['label' => __('over.oldest'), 'on_mobile' => false],
            ['label' => __('t.total_due'), 'align' => 'end'],
            ['label' => __('over.age'), 'align' => 'end'],
        ]"
        :row-count="$rows->count()"
        :empty="__('over.none')">
        @foreach ($rows as $row)
            @php $meta = $bucketMeta[$row['bucket']] ?? $bucketMeta['settled']; @endphp
            <tr wire:key="overdue-{{ $row['id'] }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                <td class="px-4 py-3 text-sm align-middle">
                    <a href="{{ route('admin.subscribers.show', $row['id']) }}"
                       class="font-medium text-mono transition hover:text-amber-700 dark:hover:text-amber-400">
                        {{ $row['name'] }}
                    </a>
                    @if ($row['phone'])
                        <div class="mt-0.5 text-xs text-muted-foreground" dir="ltr">{{ $row['phone'] }}</div>
                    @endif
                </td>

                <x-admin.cell :on-mobile="false">{{ $row['route'] ?? '—' }}</x-admin.cell>

                <x-admin.cell :on-mobile="false">
                    {{ $row['oldest'] ? period_label_i18n($row['oldest']) : '—' }}
                </x-admin.cell>

                <x-admin.cell align="end" amount :tone="$row['due'] > 0 ? 'danger' : null">
                    {{ money($row['due']) }}
                </x-admin.cell>

                <td class="px-4 py-3 text-end text-sm align-middle">
                    <span @class([
                        'kt-badge kt-badge-sm',
                        'kt-badge-destructive' => $meta['tone'] === 'danger',
                        'kt-badge-warning kt-badge-outline' => $meta['tone'] === 'warning',
                        'kt-badge-info kt-badge-outline' => $meta['tone'] === 'info',
                        'kt-badge-outline' => $meta['tone'] === 'default',
                    ])>{{ $meta['label'] }}</span>
                </td>
            </tr>
        @endforeach
    </x-admin.table>
</div>
