<div class="grid gap-4">
    <x-admin.filters :fields="$this->filterSchema()" :values="$filters" id-prefix="gb" :reset="false" />

    {{-- What the run would do, before anything is written. Steps in the order the operator
         reasons: coverage (is every reading in?), then the price, then the count. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-admin.stat
            :label="__('gen.coverage')"
            :value="ltr_isolate(number_format($progress['recorded']).' / '.number_format($progress['total']))"
            icon="ki-bill"
            :tone="$progress['pending'] > 0 ? 'warning' : 'success'"
            :hint="$progress['pending'] > 0 ? __('gen.pending_warning', ['count' => $progress['pending']]) : __('gen.coverage_ok')" />

        <x-admin.stat :label="__('gen.kwh_label')" :value="money($tariff['price'])" icon="ki-dollar"
                      :tone="$tariff['from_tariff'] ? 'success' : 'warning'"
                      :hint="$tariff['from_tariff'] ? __('gen.tariff_from') : __('gen.tariff_setting')" />

        <x-admin.stat :label="__('gen.planned')" :value="number_format($this->countInState(\App\Services\BillingService::RUN_CREATE))"
                      icon="ki-plus-squared" tone="success"
                      :hint="__('gen.existing').': '.number_format($this->countInState(\App\Services\BillingService::RUN_EXISTS))" />

        <x-admin.stat :label="__('gen.invoiced_total')" :value="money($run['invoiced'])" icon="ki-chart-line-up"
                      :hint="period_label_i18n($run['period'])" />
    </div>

    @if (! $lastRun)
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="confirm" class="kt-btn kt-btn-lg">
                {{-- ki-copy, not ki-document-duplicate: Keenicons has no such glyph and a
                     missing name renders as a blank box with no error. --}}
                <i class="ki-filled ki-copy"></i>
                {{ __('gen.run_btn') }}
            </button>
            <span class="text-xs text-muted-foreground">{{ __('gen.preview_hint') }}</span>
        </div>
    @else
        {{-- After a committed run the figures above are replaced by what was actually
             produced: re-running the preview here would show a screen full of "exists". --}}
        <div class="kt-alert kt-alert-light kt-alert-success" role="status">
            <div class="kt-alert-icon"><i class="ki-filled ki-check text-base"></i></div>
            <div class="kt-alert-content">
                <div class="kt-alert-title">
                    {{ __('gen.notif_created', ['count' => $lastRun['created']]) }}
                </div>
                <div class="kt-alert-description">
                    {{ __('gen.notif_body', ['total' => money_ltr($lastRun['invoiced']), 'skipped' => $lastRun['skipped']]) }}
                </div>
            </div>
        </div>
    @endif

    @if ($printedCount > 0)
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('print.bills', [...$printParams, 'size' => config('pdf.default_bill_size')]) }}"
               target="_blank" rel="noopener" class="kt-btn kt-btn-sm kt-btn-outline">
                <i class="ki-filled ki-printer"></i>
                {{ __('gen.print_all') }} ({{ $printedCount }})
            </a>
            <a href="{{ route('print.bills', [...$printParams, 'size' => config('pdf.default_bill_size'), 'format' => 'pdf']) }}"
               target="_blank" rel="noopener" class="kt-btn kt-btn-sm kt-btn-outline">
                <i class="ki-filled ki-document"></i>
                {{ __('gen.pdf_all') }}
            </a>
            <a href="{{ route('print.bills', [...$printParams, 'size' => config('pdf.default_bill_size'), 'format' => 'zip']) }}"
               target="_blank" rel="noopener" class="kt-btn kt-btn-sm kt-btn-outline">
                <i class="ki-filled ki-archive"></i>
                {{ __('gen.zip_all') }}
            </a>
        </div>
    @endif

    @php
        // EVERY subscriber is listed, worst first — this table is the operator's last look at
        // the run before it commits, so hiding the ordinary rows would hide the figures they
        // are checking (span, kWh, total). Issues sort to the top because those are the ones
        // that need a decision.
        $rank = fn (array $row): int => match ($row['state']) {
            \App\Services\BillingService::RUN_NO_READING => 0,
            \App\Services\BillingService::RUN_ZERO => 1,
            \App\Services\BillingService::RUN_INACTIVE => 2,
            default => 3,
        };
        $preview = collect($run['rows'])->sortBy($rank)->values();
    @endphp

    <x-admin.card :title="__('gen.summary')">
        <div class="flex flex-wrap items-center gap-2">
            @foreach ($stateLabels as $state => $label)
                <span @class([
                    'kt-badge kt-badge-sm',
                    'kt-badge-success' => $state === \App\Services\BillingService::RUN_CREATE,
                    'kt-badge-primary kt-badge-outline' => $state === \App\Services\BillingService::RUN_EXISTS,
                    'kt-badge-destructive' => $state === \App\Services\BillingService::RUN_NO_READING,
                    'kt-badge-outline' => ! in_array($state, [
                        \App\Services\BillingService::RUN_CREATE,
                        \App\Services\BillingService::RUN_EXISTS,
                        \App\Services\BillingService::RUN_NO_READING,
                    ], true),
                ])>{{ $label }}: {{ number_format($this->countInState($state)) }}</span>
            @endforeach
        </div>
    </x-admin.card>

    <x-admin.table
        :title="__('gen.detail')"
        :description="__('gen.detail_hint')"
        :columns="[
            ['label' => __('t.name')],
            ['label' => __('t.route'), 'on_mobile' => false],
            ['label' => __('t.meter'), 'on_mobile' => false],
            ['label' => __('t.reading_span'), 'align' => 'end', 'on_mobile' => false],
            ['label' => __('t.kwh'), 'align' => 'end'],
            ['label' => __('t.total'), 'align' => 'end'],
            ['label' => __('t.status'), 'align' => 'end'],
        ]"
        :row-count="$preview->count()"
        :empty="__('gen.no_issues')">
        @foreach ($preview as $row)
            <tr wire:key="run-{{ $row['subscriber_id'] }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                <td class="px-4 py-3 text-sm align-middle">
                    <a href="{{ route('admin.subscribers.show', $row['subscriber_id']) }}"
                       class="font-medium text-mono transition hover:text-amber-700 dark:hover:text-amber-400">
                        {{ $row['name'] }}
                    </a>
                </td>
                <x-admin.cell :on-mobile="false">{{ $row['route'] ?? '—' }}</x-admin.cell>
                <x-admin.cell :on-mobile="false">
                    {{ $row['meter_no'] ? ltr_isolate($row['meter_no']) : '—' }}
                </x-admin.cell>
                {{-- The span is what makes a wrong read obvious: a value well below the
                     previous one is a transposed digit, and it is visible here and nowhere
                     else before the invoice exists. --}}
                <x-admin.cell align="end" :on-mobile="false">
                    @if ($row['reading'] !== null && $row['reading_prev'] !== null)
                        <span dir="ltr" class="amount-cell">
                            {{ number_format((float) $row['reading_prev'], 0) }} → {{ number_format((float) $row['reading'], 0) }}
                        </span>
                    @else
                        —
                    @endif
                </x-admin.cell>
                <x-admin.cell align="end">
                    {{ $row['consumption'] !== null ? number_format((float) $row['consumption'], 1) : '—' }}
                </x-admin.cell>
                <x-admin.cell align="end" amount>{{ money($row['total']) }}</x-admin.cell>
                <td class="px-4 py-3 text-end text-sm align-middle">
                    <span @class([
                        'kt-badge kt-badge-sm',
                        'kt-badge-success' => $row['state'] === \App\Services\BillingService::RUN_CREATE,
                        'kt-badge-primary kt-badge-outline' => $row['state'] === \App\Services\BillingService::RUN_EXISTS,
                        'kt-badge-destructive' => $row['state'] === \App\Services\BillingService::RUN_NO_READING,
                        'kt-badge-warning kt-badge-outline' => $row['state'] === \App\Services\BillingService::RUN_INACTIVE,
                        'kt-badge-outline' => $row['state'] === \App\Services\BillingService::RUN_ZERO,
                    ])>{{ $stateLabels[$row['state']] ?? $row['state'] }}</span>
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    @if ($confirming)
        {{-- Confirmation before the only action in the panel that writes a whole month of
             invoices. The count is stated, and the pending readings are named. --}}
        <div class="kt-modal is-live" role="dialog" aria-modal="true" wire:key="run-confirm">
            <div class="kt-modal-backdrop" wire:click="cancel"></div>

            {{-- kt-modal-panel anchors the dialog to the top: a centred card re-centres on every
                 height change, which slides the controls out from under the operator's cursor.
                 See the rule in app.css for what that cost. --}}
            <div class="kt-modal-panel relative flex min-h-full w-full justify-center">
                <div class="kt-modal-content w-full max-w-md">
                    <div class="kt-modal-header">
                        <h3 class="kt-modal-title">{{ __('gen.confirm_heading') }}</h3>
                        <button type="button" class="kt-modal-close" wire:click="cancel" aria-label="{{ __('t.close') }}">
                            <i class="ki-filled ki-cross"></i>
                        </button>
                    </div>

                    <div class="kt-modal-body grid gap-3">
                        <p class="text-sm text-mono">
                            {{ __('gen.confirm_body', [
                                'count' => $this->countInState(\App\Services\BillingService::RUN_CREATE),
                                'period' => period_label_i18n($this->period()),
                            ]) }}
                        </p>

                        @if ($progress['pending'] > 0)
                            <div class="kt-alert kt-alert-light kt-alert-warning" role="alert">
                                <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                                <div class="kt-alert-content">
                                    <div class="kt-alert-title">{{ __('gen.pending_warning', ['count' => $progress['pending']]) }}</div>
                                    <div class="kt-alert-description">{{ __('gen.pending_advice') }}</div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="kt-modal-footer">
                        <button type="button" wire:click="cancel" class="kt-btn kt-btn-outline">
                            {{ __('t.cancel') }}
                        </button>
                        <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" class="kt-btn">
                            <i class="ki-filled ki-copy"></i>
                            {{ __('gen.run_btn') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
