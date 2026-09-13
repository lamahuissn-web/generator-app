<div class="grid gap-4">
    @if (session('status'))
        <div class="kt-alert kt-alert-light kt-alert-success" role="status">
            <div class="kt-alert-icon"><i class="ki-filled ki-check text-base"></i></div>
            <div class="kt-alert-content">
                <div class="kt-alert-title">{{ session('status') }}</div>
            </div>
        </div>
    @endif

    {{-- 1) The period. Chips first because that is how the office thinks about a month, and the two
         dates stay available for the range a chip cannot express. --}}
    <div class="grid gap-3">
        <div class="flex flex-wrap items-center gap-2">
            @foreach (['this_month' => 'col.preset_this_month', 'last_month' => 'col.preset_last_month', 'last_7' => 'col.preset_last_7', 'all_time' => 'col.preset_all_time'] as $key => $label)
                <button type="button" wire:click="setPreset('{{ $key }}')"
                        @class(['kt-btn kt-btn-sm', 'kt-btn-primary' => $preset === $key, 'kt-btn-outline' => $preset !== $key])>
                    {{ __($label) }}
                </button>
            @endforeach
        </div>

        <x-admin.filters :fields="$this->filterSchema()" :values="$filters" id-prefix="hub" :reset="false" />
    </div>

    {{-- Every figure names its scope. Two of these are the period and one is all-time; unlabelled,
         they get added up and reported as an error. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-admin.stat :label="__('col.collected').' — '.__('col.scope_period')" :value="money($totals['collected'])"
                      icon="ki-wallet" tone="success" :hint="$periodLabel" />
        <x-admin.stat :label="__('col.handed').' — '.__('col.scope_period')" :value="money($totals['handed'])"
                      icon="ki-bank" :hint="$periodLabel" />
        <x-admin.stat :label="__('col.in_hand_total').' — '.__('col.scope_all_time')" :value="money($totals['in_hand'])"
                      icon="ki-arrows-circle" :tone="$totals['in_hand'] > 0 ? 'warning' : 'success'"
                      :hint="__('col.in_hand_total_hint')" />
        <x-admin.stat :label="__('col.payments_count').' — '.__('col.scope_period')"
                      :value="number_format($totals['payments_count'])" icon="ki-receipt-square"
                      :hint="__('col.select_hint')" />
    </div>

    {{-- 2) One row per collector: the figures in the period beside the cash he still holds, because
         the gap between them is what decides the next action. --}}
    <x-admin.table
        :title="__('col.collectors_title')"
        :description="__('col.select_hint')"
        :columns="[
            ['label' => __('col.collector')],
            ['label' => __('col.hdr_collector_count'), 'align' => 'end', 'on_mobile' => false],
            ['label' => __('col.hdr_collected_period'), 'align' => 'end'],
            ['label' => __('col.hdr_handed_period'), 'align' => 'end', 'on_mobile' => false],
            ['label' => __('col.hdr_in_hand_all'), 'align' => 'end'],
            ['label' => __('col.last_handover'), 'on_mobile' => false],
            ['label' => __('t.status'), 'on_mobile' => false],
            ['label' => '', 'align' => 'end', 'on_mobile' => false],
        ]"
        :row-count="$collectors->count()"
        :empty="__('col.need_collectors')">
        @foreach ($collectors as $row)
            @php($isSelected = ($selected['collector_id'] ?? null) === $row['collector_id'])
            <tr wire:key="hub-collector-{{ $row['collector_id'] }}"
                @class(['border-b border-border last:border-b-0 transition', 'bg-muted/40' => $isSelected])>
                <td class="px-4 py-3 text-sm align-middle">
                    <button type="button" wire:click="selectCollector({{ $row['collector_id'] }})"
                            class="font-medium text-mono transition hover:text-amber-700 dark:hover:text-amber-400">
                        {{ $row['name'] }}
                    </button>
                </td>
                <x-admin.cell align="end" :on-mobile="false">{{ number_format($row['payments_count']) }}</x-admin.cell>
                <x-admin.cell align="end" amount>{{ money($row['collected']) }}</x-admin.cell>
                <x-admin.cell align="end" amount :on-mobile="false">{{ money($row['handed']) }}</x-admin.cell>
                {{-- The all-time position: what the operator counts in the drawer. --}}
                <x-admin.cell align="end" amount :tone="$row['in_hand'] > 0 ? 'warning' : 'success'">
                    {{ money($row['in_hand']) }}
                </x-admin.cell>
                <x-admin.cell :on-mobile="false" muted>
                    @if ($row['last_handover'])
                        <span dir="ltr">{{ $row['last_handover'] }}</span>
                        —
                        {{ $row['since_days'] === 0 ? __('col.since_today') : __('col.since_days', ['days' => $row['since_days']]) }}
                    @else
                        {{ __('col.never_handed') }}
                    @endif
                </x-admin.cell>
                <x-admin.cell :on-mobile="false">
                    <span @class(['kt-badge kt-badge-sm', 'kt-badge-warning' => $row['in_hand'] > 0, 'kt-badge-success' => $row['in_hand'] <= 0])>
                        {{ $row['in_hand'] > 0 ? __('col.status_waiting') : __('col.status_clear') }}
                    </span>
                </x-admin.cell>
                <td class="px-4 py-3 text-sm align-middle text-end">
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <button type="button" wire:click="selectCollector({{ $row['collector_id'] }}); openHandover()"
                                class="kt-btn kt-btn-sm">
                            <i class="ki-filled ki-bank"></i>
                            {{ __('col.handover_create') }}
                        </button>
                        <button type="button" wire:click="selectCollector({{ $row['collector_id'] }})"
                                class="kt-btn kt-btn-sm kt-btn-outline">
                            {{ __('col.tab_ledger') }}
                        </button>
                    </div>
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    @if (! $collector)
        <x-admin.card>
            <p class="text-sm text-muted-foreground">{{ __('col.need_collector') }}</p>
        </x-admin.card>
    @else
        {{-- 3) The selected collector's file, in the same page. --}}
        <x-admin.card
            :title="$collector->name"
            :description="$periodLabel.' · '.__('col.audit_note')">
            <x-slot:actions>
                <button type="button" wire:click="openHandover" class="kt-btn kt-btn-sm">
                    <i class="ki-filled ki-plus"></i>
                    {{ __('col.handover_create') }}
                </button>
                <button type="button" wire:click="exportAudit" class="kt-btn kt-btn-sm kt-btn-outline">
                    <i class="ki-filled ki-file-down"></i>
                    {{ __('col.export_audit') }}
                </button>
                <a href="{{ route('print.collector-statement', [...$exportParams, 'size' => 'a4']) }}" target="_blank" rel="noopener"
                   class="kt-btn kt-btn-sm kt-btn-outline">
                    <i class="ki-filled ki-printer"></i>
                    {{ __('col.print') }}
                </a>
                <a href="{{ route('print.collector-statement', [...$exportParams, 'size' => 'a4', 'format' => 'pdf']) }}"
                   target="_blank" rel="noopener" class="kt-btn kt-btn-sm kt-btn-outline">
                    <i class="ki-filled ki-document"></i>
                    {{ __('col.pdf') }}
                </a>
            </x-slot:actions>
        </x-admin.card>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <x-admin.stat :label="__('col.collected').' — '.__('col.scope_period')"
                          :value="money($ledger['totals']['debit'] ?? 0)" icon="ki-arrow-down" tone="success"
                          :hint="$periodLabel" />
            <x-admin.stat :label="__('col.handed').' — '.__('col.scope_period')"
                          :value="money($ledger['totals']['credit'] ?? 0)" icon="ki-bank" :hint="$periodLabel" />
            <x-admin.stat :label="__('col.in_hand_now').' — '.__('col.scope_all_time')"
                          :value="money($selected['in_hand'])" icon="ki-wallet"
                          :tone="$selected['in_hand'] > 0 ? 'warning' : 'success'"
                          :hint="__('col.in_hand_total_hint')" />
            <x-admin.stat :label="__('col.payments_count').' — '.__('col.scope_all_time')"
                          :value="number_format($ledger['rows_total'] ?? 0)" icon="ki-receipt-square" />
            <x-admin.stat :label="__('col.delivery_rate').' — '.__('col.scope_period')"
                          :value="number_format($selected['collected'] > 0 ? round($ledger['totals']['credit'] / $selected['collected'] * 100) : 0).'%'"
                          icon="ki-percentage" :hint="__('col.of_collected')" />
        </div>

        {{-- The dossier's tabs. Each label carries its scope so the counts cannot be read as one
             measurement across the four. --}}
        <div class="flex flex-wrap items-center gap-2">
            @foreach ([
                'ledger' => __('col.tab_ledger').' ('.__('col.scope_all_time').')',
                'audit' => __('col.tab_audit').' ('.__('col.scope_period').' · '.number_format($documents['rows_total']).')',
                'handovers' => __('col.tab_handovers').' ('.__('col.scope_all_time').' · '.number_format($handoverRows->count()).')',
                'log' => __('col.tab_log').' ('.number_format($trail['rows_total']).')',
            ] as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')"
                        @class(['kt-btn kt-btn-sm', 'kt-btn-primary' => $tab === $key, 'kt-btn-outline' => $tab !== $key])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($tab === 'ledger')
            {{-- A running balance cannot be read from mid-stream: it is all-time unless the operator
                 cuts it, and the cut view carries the opening balance as its first row. --}}
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="toggleCut"
                        @class(['kt-btn kt-btn-sm', 'kt-btn-primary' => $cutToPeriod, 'kt-btn-outline' => ! $cutToPeriod])>
                    <i class="ki-filled ki-arrows-circle"></i>
                    {{ __('col.cut_period') }}
                </button>
                <span class="text-xs text-muted-foreground">
                    {{ $cutToPeriod ? __('col.cut_on') : __('col.cut_off') }}
                </span>
            </div>

            <x-admin.table
                :columns="[
                    ['label' => __('t.date')],
                    ['label' => __('t.details')],
                    ['label' => __('col.led_debit'), 'align' => 'end'],
                    ['label' => __('col.led_credit'), 'align' => 'end'],
                    ['label' => __('col.led_balance'), 'align' => 'end'],
                ]"
                :row-count="count($ledger['rows'] ?? [])"
                :empty="__('col.led_empty')">
                <tr class="border-b border-border bg-muted/40">
                    <td class="px-4 py-3 text-sm align-middle" colspan="4">
                        <span class="text-2xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ $cutToPeriod
                                ? __('col.opening_row', ['date' => $this->from()->toDateString()])
                                : __('col.led_opening') }}
                        </span>
                    </td>
                    <x-admin.cell align="end" amount>{{ money($ledger['totals']['opening'] ?? 0) }}</x-admin.cell>
                </tr>

                @foreach ($ledger['rows'] ?? [] as $entry)
                    <tr wire:key="hub-ledger-{{ $entry['ref'] }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                        <td class="px-4 py-3 text-sm align-middle">
                            <span dir="ltr" class="text-secondary-foreground">{{ $entry['label'] }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm align-middle">
                            <span class="text-secondary-foreground">{{ $entry['description'] }}</span>
                            @if (! empty($entry['detail']))
                                <div class="mt-0.5 text-xs text-muted-foreground">{{ $entry['detail'] }}</div>
                            @endif
                        </td>
                        <x-admin.cell align="end" amount :tone="$entry['due'] > 0 ? 'success' : null">
                            {{ $entry['due'] > 0 ? money($entry['due']) : '—' }}
                        </x-admin.cell>
                        <x-admin.cell align="end" amount :tone="$entry['settled'] > 0 ? 'warning' : null">
                            {{ $entry['settled'] > 0 ? money($entry['settled']) : '—' }}
                        </x-admin.cell>
                        <x-admin.cell align="end" amount>{{ money($entry['balance']) }}</x-admin.cell>
                    </tr>
                @endforeach

                <tr class="border-t-2 border-border">
                    <td class="px-4 py-3 text-sm align-middle" colspan="4">
                        <span class="text-2xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ __('col.led_closing') }}
                        </span>
                    </td>
                    <x-admin.cell align="end" amount>
                        <span class="font-semibold">{{ money($ledger['totals']['closing'] ?? 0) }}</span>
                    </x-admin.cell>
                </tr>
            </x-admin.table>

            <p class="text-xs text-muted-foreground">{{ __('col.opening_note') }}</p>

            @if (($ledger['totals']['closing'] ?? 0) !== round($selected['in_hand'], 2))
                {{-- The ledger and the handovers have drifted apart; the operator needs to know
                     before counting the drawer, not after. --}}
                <div class="kt-alert kt-alert-light kt-alert-destructive" role="alert">
                    <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                    <div class="kt-alert-content">
                        <div class="kt-alert-title">{{ __('col.led_mismatch') }}</div>
                    </div>
                </div>
            @endif
        @endif

        @if ($tab === 'audit')
            {{-- The auditing view: one row per receipt, grouped by the day the collector went out,
                 answering "when exactly did he take money from a subscriber, and against what". --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="kt-input kt-input-sm w-full sm:w-56">
                    <i class="ki-filled ki-magnifier"></i>
                    <input type="search" wire:model.live.debounce.300ms="auditSearch"
                           placeholder="{{ __('col.audit_search') }}" autocomplete="off">
                </div>

                <button type="button" wire:click="$set('auditMethod', null)"
                        @class(['kt-btn kt-btn-sm', 'kt-btn-primary' => $auditMethod === null, 'kt-btn-outline' => $auditMethod !== null])>
                    {{ __('col.audit_method_all') }}
                </button>
                @foreach (collect($documents['days'])->flatMap(fn ($day) => $day['rows'])->pluck('method', 'method_key')->unique() as $key => $label)
                    <button type="button" wire:click="$set('auditMethod', '{{ $key }}')"
                            @class(['kt-btn kt-btn-sm', 'kt-btn-primary' => $auditMethod === $key, 'kt-btn-outline' => $auditMethod !== $key])>
                        {{ $label }}
                    </button>
                @endforeach

                <span class="text-xs text-muted-foreground">
                    {{ __('col.audit_count', ['count' => number_format($documents['rows_total']), 'total' => money($documents['total'])]) }}
                </span>
            </div>

            <x-admin.table
                :columns="[
                    ['label' => __('col.audit_time')],
                    ['label' => __('col.audit_receipt')],
                    ['label' => __('col.payer')],
                    ['label' => __('t.route'), 'on_mobile' => false],
                    ['label' => __('t.amount'), 'align' => 'end'],
                    ['label' => __('col.method'), 'on_mobile' => false],
                    ['label' => __('col.for_invoice'), 'on_mobile' => false],
                    ['label' => __('col.collector_note'), 'on_mobile' => false],
                    ['label' => __('col.recorded_by'), 'on_mobile' => false],
                ]"
                :row-count="$documents['rows_total']"
                :empty="__('col.audit_empty')">
                @foreach ($documents['days'] as $day)
                    <tr wire:key="hub-day-{{ $day['day'] }}" class="bg-muted/40">
                        <td class="px-4 py-3 text-sm align-middle" colspan="9">
                            <span class="text-2xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {{ __('col.audit_day', ['date' => $day['day'], 'count' => $day['count'], 'total' => money($day['total'])]) }}
                            </span>
                        </td>
                    </tr>

                    @foreach ($day['rows'] as $row)
                        <tr wire:key="hub-doc-{{ $row['ref'] }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                            <td class="px-4 py-3 text-sm align-middle">
                                <span dir="ltr" class="text-secondary-foreground">{{ $row['time'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm align-middle">
                                <span dir="ltr" class="text-mono">{{ $row['number'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm align-middle font-medium text-mono">
                                {{ $row['subscriber'] }}
                                @if ($row['meter_no'])
                                    <div class="text-xs font-normal text-muted-foreground">
                                        {{ __('t.meter_no') }}: <span dir="ltr">{{ $row['meter_no'] }}</span>
                                    </div>
                                @endif
                            </td>
                            <x-admin.cell :on-mobile="false" muted>{{ $row['route'] }}</x-admin.cell>
                            <x-admin.cell align="end" amount>
                                {{ money($row['amount']) }}
                            </x-admin.cell>
                            <x-admin.cell :on-mobile="false">
                                {{ $row['method'] }}
                                @if ($row['lira'])
                                    <div class="text-xs text-muted-foreground" dir="ltr">{{ $row['lira'] }}</div>
                                @endif
                            </x-admin.cell>
                            <x-admin.cell :on-mobile="false" muted>
                                {{ $row['invoices'] === [] ? '—' : implode('، ', $row['invoices']) }}
                            </x-admin.cell>
                            <x-admin.cell :on-mobile="false" muted>{{ $row['note'] ?: '—' }}</x-admin.cell>
                            <x-admin.cell :on-mobile="false" muted>{{ $row['recorder_label'] }}</x-admin.cell>
                        </tr>
                    @endforeach
                @endforeach
            </x-admin.table>

            <p class="text-xs text-muted-foreground">{{ __('col.audit_note') }}</p>
        @endif

        @if ($tab === 'handovers')
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="openHandover" class="kt-btn kt-btn-sm">
                    <i class="ki-filled ki-plus"></i>
                    {{ __('col.handover_create') }}
                </button>
                <span class="text-xs text-muted-foreground">{{ __('col.handover_helper_row') }}</span>
            </div>

            <x-admin.table
                :columns="[
                    ['label' => __('t.date')],
                    ['label' => __('t.amount'), 'align' => 'end'],
                    ['label' => __('col.note')],
                    ['label' => __('col.recorded_by'), 'on_mobile' => false],
                    ['label' => '', 'align' => 'end', 'on_mobile' => false],
                ]"
                :row-count="$handoverRows->count()"
                :empty="__('col.empty')">
                @foreach ($handoverRows as $handoverRow)
                    <tr wire:key="hub-handover-{{ $handoverRow->id }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                        <td class="px-4 py-3 text-sm align-middle">
                            <span dir="ltr" class="text-secondary-foreground">{{ $handoverRow->handover_date?->format('Y-m-d') }}</span>
                        </td>
                        <x-admin.cell align="end" amount>{{ money($handoverRow->amount) }}</x-admin.cell>
                        <x-admin.cell muted>{{ $handoverRow->note ?: '—' }}</x-admin.cell>
                        <x-admin.cell :on-mobile="false" muted>{{ $handoverRow->recordedBy?->name ?? __('col.recorder_unknown') }}</x-admin.cell>
                        <td class="px-4 py-3 text-sm align-middle text-end">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <button type="button" wire:click="openHandover({{ $handoverRow->id }})" class="kt-btn kt-btn-sm kt-btn-outline">
                                    <i class="ki-filled ki-pencil"></i>
                                    {{ __('t.edit') }}
                                </button>
                                <button type="button" wire:click="deleteHandover({{ $handoverRow->id }})"
                                        wire:confirm="{{ __('col.delete_warning_row', ['amount' => money($handoverRow->amount), 'name' => $collector->name]) }}"
                                        class="kt-btn kt-btn-sm kt-btn-ghost text-destructive">
                                    <i class="ki-filled ki-trash"></i>
                                    {{ __('t.delete') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif

        @if ($tab === 'log')
            {{-- The append-only record: an event whose document was edited or deleted keeps its line
                 here, with the balance that followed it. --}}
            <div class="flex flex-wrap items-center gap-2">
                @foreach (['all' => 'col.log_kind_all', 'payment' => 'col.log_kind_payment', 'handover' => 'col.log_kind_handover', 'edits' => 'col.log_kind_edits'] as $key => $label)
                    <button type="button" wire:click="$set('logKind', '{{ $key }}')"
                            @class(['kt-btn kt-btn-sm', 'kt-btn-primary' => $logKind === $key, 'kt-btn-outline' => $logKind !== $key])>
                        {{ __($label) }}
                    </button>
                @endforeach

                <span class="text-xs text-muted-foreground">
                    {{ __('col.log_count', ['count' => number_format($trail['rows_total']), 'recorded' => number_format($trail['recorded']), 'unrecorded' => number_format($trail['unrecorded'])]) }}
                </span>
            </div>

            <x-admin.table
                :columns="[
                    ['label' => __('col.log_time')],
                    ['label' => __('col.log_event')],
                    ['label' => __('col.log_detail')],
                    ['label' => __('t.amount'), 'align' => 'end'],
                    ['label' => __('col.log_balance_after'), 'align' => 'end'],
                    ['label' => __('col.log_actor'), 'on_mobile' => false],
                ]"
                :row-count="count($trail['rows'])"
                :empty="__('col.log_empty')">
                @foreach ($trail['rows'] as $entry)
                    <tr wire:key="hub-log-{{ $entry['kind'] }}-{{ $entry['seq'] }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                        <td class="px-4 py-3 text-sm align-middle">
                            <span dir="ltr" class="text-secondary-foreground">{{ $entry['at']?->format('Y-m-d H:i:s') }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm align-middle">
                            <span @class([
                                'kt-badge kt-badge-sm',
                                'kt-badge-success' => in_array($entry['kind'], ['payment'], true),
                                'kt-badge-warning' => in_array($entry['kind'], ['handover'], true),
                                'kt-badge-outline' => in_array($entry['kind'], ['void', 'edit', 'delete'], true),
                            ])>{{ __('col.event_'.$entry['kind']) }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm align-middle">
                            <span class="text-secondary-foreground">
                                @if ($entry['number'])
                                    <span dir="ltr" class="text-mono">{{ $entry['number'] }}</span> —
                                @endif
                                {{ $entry['detail'] ?: __('col.led_handover') }}
                            </span>
                            @if ($entry['replaced'])
                                <div class="mt-0.5 text-xs text-muted-foreground">
                                    {{ __('col.event_edit_detail', ['before' => money($entry['replaced']), 'after' => money($entry['amount'])]) }}
                                </div>
                            @endif
                            @if ($entry['note'])
                                <div class="mt-0.5 text-xs text-muted-foreground">{{ $entry['note'] }}</div>
                            @endif
                        </td>
                        <x-admin.cell align="end" amount :tone="$entry['delta'] > 0 ? 'success' : 'warning'">
                            {{ money($entry['amount']) }}
                        </x-admin.cell>
                        <x-admin.cell align="end" amount>{{ money($entry['balance']) }}</x-admin.cell>
                        <x-admin.cell :on-mobile="false" muted>
                            {{ $entry['actor'] ?? __('col.recorder_unknown') }}
                        </x-admin.cell>
                    </tr>
                @endforeach
            </x-admin.table>

            @if ($trail['rows_total'] > count($trail['rows']))
                <p class="text-xs text-muted-foreground">
                    {{ __('col.more_events', ['count' => number_format($trail['rows_total'] - count($trail['rows']))]) }}
                </p>
            @endif

            <p class="text-xs text-muted-foreground">{{ __('col.log_note') }}</p>
        @endif
    @endif

    {{-- 4) What the report screen showed, still here, collapsed: nothing the operator could see
         before was dropped in the merge. --}}
    <x-admin.card>
        <details>
            <summary class="cursor-pointer text-sm font-semibold">
                {{ __('col.by_area_day') }}
                <span class="text-xs font-normal text-muted-foreground">— {{ __('col.by_area_day_hint') }}</span>
            </summary>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <x-admin.table :title="__('col.by_route')" :columns="[
                        ['label' => __('t.route')],
                        ['label' => __('col.payments_count'), 'align' => 'end', 'on_mobile' => false],
                        ['label' => __('t.amount'), 'align' => 'end'],
                    ]" :row-count="$routeRows->count()">
                    @foreach ($routeRows as $row)
                        <tr wire:key="hub-route-{{ $row['route_id'] ?? 'none' }}" class="border-b border-border last:border-b-0">
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
                        <tr wire:key="hub-dayrow-{{ $row['day'] }}" class="border-b border-border last:border-b-0">
                            <td class="px-4 py-3 text-sm align-middle">
                                <span dir="ltr" class="text-secondary-foreground">{{ $row['day'] }}</span>
                            </td>
                            <x-admin.cell align="end" :on-mobile="false">{{ number_format($row['payments_count']) }}</x-admin.cell>
                            <x-admin.cell align="end" amount>{{ money($row['collected']) }}</x-admin.cell>
                        </tr>
                    @endforeach
                </x-admin.table>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button type="button" wire:click="exportReport" class="kt-btn kt-btn-sm kt-btn-outline">
                    <i class="ki-filled ki-file-down"></i>
                    {{ __('col.export') }}
                </button>
                <a href="{{ route('print.collection-report', [...$reportParams, 'size' => 'a4']) }}" target="_blank" rel="noopener"
                   class="kt-btn kt-btn-sm kt-btn-outline">
                    <i class="ki-filled ki-printer"></i>
                    {{ __('col.print') }}
                </a>
                <a href="{{ route('print.collection-report', [...$reportParams, 'size' => 'a4', 'format' => 'pdf']) }}"
                   target="_blank" rel="noopener" class="kt-btn kt-btn-sm kt-btn-outline">
                    <i class="ki-filled ki-document"></i>
                    {{ __('col.pdf') }}
                </a>
                <span class="text-xs text-muted-foreground">{{ __('col.affected_note') }}</span>
            </div>
        </details>
    </x-admin.card>

    @if ($handoverOpen)
        {{-- The one place a settlement is written now. In the DOM only while open, so no backdrop
             can outlive the tables underneath it. --}}
        <div class="kt-modal is-live" role="dialog" aria-modal="true" wire:key="hub-handover-modal">
            <div class="kt-modal-backdrop" wire:click="closeHandover"></div>

            <div class="kt-modal-panel relative flex min-h-full w-full justify-center">
                <div class="kt-modal-content w-full max-w-md">
                    <div class="kt-modal-header">
                        <h3 class="kt-modal-title">
                            {{ $handoverId ? __('col.handover_edit') : __('col.handover_create') }}
                            — {{ $collector?->name ?? '' }}
                        </h3>
                        <button type="button" class="kt-modal-close" wire:click="closeHandover" aria-label="{{ __('t.close') }}">
                            <i class="ki-filled ki-cross"></i>
                        </button>
                    </div>

                    <form wire:submit="saveHandover">
                        <div class="kt-modal-body grid gap-4">
                            <p class="text-sm text-muted-foreground">
                                {{ __('col.handover_available', ['amount' => money_ltr($available)]) }}
                                {{ __('col.handover_available_hint') }}
                            </p>

                            <x-admin.field :field="[
                                    'key' => 'amount',
                                    'label' => __('col.amount'),
                                    'type' => 'money',
                                    'helper' => __('col.handover_helper'),
                                    'rules' => ['required'],
                                ]" model="handover" :values="$handover" id-prefix="hub-ho" reserve-error />

                            <x-admin.field :field="[
                                    'key' => 'handover_date',
                                    'label' => __('col.date'),
                                    'type' => 'date',
                                    'rules' => ['required'],
                                ]" model="handover" :values="$handover" id-prefix="hub-ho" reserve-error />

                            <x-admin.field :field="[
                                    'key' => 'note',
                                    'label' => __('col.note'),
                                    'rules' => ['nullable'],
                                ]" model="handover" :values="$handover" id-prefix="hub-ho" reserve-error />
                        </div>

                        <div class="kt-modal-footer">
                            @if ($handoverId)
                                <button type="button" wire:click="deleteHandover"
                                        wire:confirm="{{ __('col.delete_warning_row', ['amount' => money($handover['amount'] ?? 0), 'name' => $collector?->name ?? '']) }}"
                                        class="kt-btn kt-btn-outline text-destructive">
                                    <i class="ki-filled ki-trash"></i>
                                    {{ __('col.handover_delete') }}
                                </button>
                            @endif
                            <button type="button" wire:click="closeHandover" class="kt-btn kt-btn-outline">
                                {{ __('t.cancel') }}
                            </button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveHandover" class="kt-btn">
                                <i class="ki-filled ki-check"></i>
                                {{ $handoverId ? __('t.save') : __('col.handover_create') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
