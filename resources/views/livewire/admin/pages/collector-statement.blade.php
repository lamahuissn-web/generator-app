<div class="grid gap-4">
    <x-admin.filters :fields="$this->filterSchema()" :values="$filters" id-prefix="cs" :reset="false" />

    @if (! $collector)
        <x-admin.card>
            <p class="text-sm text-muted-foreground">{{ __('col.led_need_collector') }}</p>
        </x-admin.card>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            {{-- In hand is ALL-TIME and the range figures sit beside it, so the two can never
                 be read as the same measurement. --}}
            <x-admin.stat :label="__('col.in_hand')" :value="money($inHand)" icon="ki-wallet"
                          :tone="$inHand > 0 ? 'warning' : 'success'" :hint="$collector->name" />
            <x-admin.stat :label="__('col.collected')" :value="money($ledger['totals']['debit'] ?? 0)"
                          icon="ki-arrow-down" tone="success" :hint="$periodLabel" />
            <x-admin.stat :label="__('col.handed')" :value="money($handedInRange)" icon="ki-bank"
                          :hint="__('col.from').': '.$periodLabel" />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="openHandover" class="kt-btn kt-btn-sm">
                <i class="ki-filled ki-plus"></i>
                {{ __('col.quick_handover') }}
            </button>
            <button type="button" wire:click="toggleAllTime" class="kt-btn kt-btn-sm kt-btn-outline">
                <i class="ki-filled ki-arrows-circle"></i>
                {{ $allTime ? __('col.led_back_to_range') : __('col.st_all_time') }}
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
            <a href="{{ route('admin.collection-report', array_filter([
                    'from' => $exportParams['from'] ?? null,
                    'to' => $exportParams['to'] ?? null,
                    'collector_id' => $collector->id,
                ])) }}" class="kt-btn kt-btn-sm kt-btn-ghost">
                <i class="ki-filled ki-arrow-left rtl:rotate-180"></i>
                {{ __('col.back_to_report') }}
            </a>
        </div>

        {{-- Opening balance is a line of the table, not a caption: the operator reconciles
             the running balance down the page and needs the starting figure in the column. --}}
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
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ __('col.led_opening') }}
                    </span>
                </td>
                <x-admin.cell align="end" amount>{{ money($ledger['totals']['opening'] ?? 0) }}</x-admin.cell>
            </tr>

            @foreach ($ledger['rows'] ?? [] as $entry)
                <tr wire:key="ledger-{{ $entry['ref'] }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
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
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ __('col.led_closing') }}
                    </span>
                </td>
                <x-admin.cell align="end" amount>
                    <span class="font-semibold">{{ money($ledger['totals']['closing'] ?? 0) }}</span>
                </x-admin.cell>
            </tr>
        </x-admin.table>

        @if (($ledger['totals']['closing'] ?? 0) !== round($inHand, 2))
            {{-- The closing balance must equal the all-time cash position; if they ever
                 disagree, the ledger and the handover totals have drifted apart and the
                 operator needs to know before counting the drawer. --}}
            <div class="kt-alert kt-alert-light kt-alert-destructive" role="alert">
                <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                <div class="kt-alert-content">
                    <div class="kt-alert-title">{{ __('col.led_mismatch') }}</div>
                </div>
            </div>
        @endif
    @endif

    @if ($handoverOpen)
        {{-- Same shape as the record actions: in the DOM only while open, so no backdrop can
             outlive the table underneath it. --}}
        <div class="kt-modal is-live" role="dialog" aria-modal="true" wire:key="handover-modal">
            <div class="kt-modal-backdrop" wire:click="closeHandover"></div>

            {{-- kt-modal-panel anchors the dialog to the top: a centred card re-centres on every
                 height change, which slides the controls out from under the operator's cursor.
                 See the rule in app.css for what that cost. --}}
            <div class="kt-modal-panel relative flex min-h-full w-full justify-center">
                <div class="kt-modal-content w-full max-w-md">
                    <div class="kt-modal-header">
                        <h3 class="kt-modal-title">{{ __('col.quick_handover_heading', ['name' => $collector?->name ?? '']) }}</h3>
                        <button type="button" class="kt-modal-close" wire:click="closeHandover" aria-label="{{ __('t.close') }}">
                            <i class="ki-filled ki-cross"></i>
                        </button>
                    </div>

                    <form wire:submit="saveHandover">
                        <div class="kt-modal-body grid gap-4">
                            <p class="text-sm text-muted-foreground">
                                {{ __('col.quick_handover_desc', ['amount' => money_ltr($inHand)]) }}
                            </p>

                            <x-admin.field :field="[
                                    'key' => 'amount',
                                    'label' => __('col.amount'),
                                    'type' => 'money',
                                    'helper' => __('col.quick_handover_helper'),
                                    'rules' => ['required'],
                                ]" model="handover" :values="$handover" id-prefix="ho" reserve-error />

                            <x-admin.field :field="[
                                    'key' => 'handover_date',
                                    'label' => __('col.date'),
                                    'type' => 'date',
                                    'rules' => ['required'],
                                ]" model="handover" :values="$handover" id-prefix="ho" reserve-error />

                            <x-admin.field :field="[
                                    'key' => 'note',
                                    'label' => __('col.note'),
                                    'rules' => ['nullable'],
                                ]" model="handover" :values="$handover" id-prefix="ho" reserve-error />
                        </div>

                        <div class="kt-modal-footer">
                            <button type="button" wire:click="closeHandover" class="kt-btn kt-btn-outline">
                                {{ __('t.cancel') }}
                            </button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveHandover" class="kt-btn">
                                <i class="ki-filled ki-check"></i>
                                {{ __('col.quick_handover') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
