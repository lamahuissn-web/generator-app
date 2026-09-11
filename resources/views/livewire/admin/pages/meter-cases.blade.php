@php
    // State -> chip tone. One map, so a case cannot be coloured in one place and described
    // differently in the CSV.
    $stateChip = [
        \App\Reports\MeterCaseBoard::STATE_NEW => ['tone' => 'kt-badge-warning kt-badge-outline', 'key' => 'mc.chip_new'],
        \App\Reports\MeterCaseBoard::STATE_DECISION => ['tone' => 'kt-badge-destructive', 'key' => 'mc.chip_decision'],
    ];
@endphp

<div class="grid gap-4">
    <x-admin.filters :fields="$this->filterSchema()" :values="$filters" id-prefix="mc" :reset="false" />

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-admin.stat :label="__('mc.stat_open')" :value="number_format($summary['total'])" icon="ki-shield-cross"
                      :tone="$summary['total'] > 0 ? 'warning' : 'success'"
                      :hint="__('mc.stat_open_hint')" />

        <x-admin.stat :label="__('mc.stat_decision')" :value="number_format($summary['decision'])" icon="ki-information-2"
                      :tone="$summary['decision'] > 0 ? 'danger' : 'success'"
                      :hint="__('mc.stat_decision_hint')" />

        <x-admin.stat :label="__('mc.stat_unread')" :value="number_format($summary['unread_cycle'])" icon="ki-bill"
                      :tone="$summary['unread_cycle'] > 0 ? 'warning' : 'success'"
                      :hint="__('mc.stat_unread_hint')" />

        <x-admin.stat :label="__('mc.stat_oldest')"
                      :value="$summary['oldest'] === null ? '—' : number_format((int) $summary['oldest'])"
                      icon="ki-time" :tone="($summary['oldest'] ?? 0) >= 3 ? 'danger' : 'default'"
                      :hint="$summary['oldest'] === null
                          ? __('mc.stat_oldest_hint')
                          : ((int) $summary['oldest'] === 0 ? __('rdgb.this_cycle') : plural('rdgb.months_ago', (int) $summary['oldest']))" />
    </div>

    {{-- The policy, stated where the decisions are made rather than in a manual: one estimate, and
         after that the case is a choice about the device. --}}
    <div class="kt-alert kt-alert-light kt-alert-warning" role="note">
        <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
        <div class="kt-alert-content">
            <div class="kt-alert-title">{{ __('mc.policy_title') }}</div>
            <div class="kt-alert-description">{{ __('mc.policy_body') }}</div>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="text-xs text-muted-foreground">{{ __('mc.list_hint') }}</span>
        <button type="button" wire:click="export" class="kt-btn kt-btn-sm kt-btn-outline">
            <i class="ki-filled ki-file-down"></i>
            {{ __('over.export_btn') }}
        </button>
    </div>

    @if ($rows->isEmpty())
        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center gap-2 py-10 text-center">
                <span class="tile tile-success size-12 text-xl"><i class="ki-filled ki-check-circle"></i></span>
                <span class="font-semibold text-mono">{{ __('mc.none') }}</span>
                <span class="text-sm text-muted-foreground">{{ __('mc.none_body') }}</span>
            </div>
        </div>
    @endif

    @error('clear')
        <div class="kt-alert kt-alert-light kt-alert-destructive" role="alert">
            <div class="kt-alert-content"><div class="kt-alert-title">{{ $message }}</div></div>
        </div>
    @enderror

    <div class="grid gap-3">
        @foreach ($rows as $row)
            @php $chip = $stateChip[$row['state']] ?? $stateChip[\App\Reports\MeterCaseBoard::STATE_NEW]; @endphp

            <div class="kt-card" wire:key="case-{{ $row['id'] }}">
                <div class="kt-card-content grid gap-3 py-4">
                    {{-- Who, and the state of the case --}}
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.subscribers.show', $row['id']) }}"
                           class="truncate font-semibold text-mono transition hover:text-amber-700 dark:hover:text-amber-400">
                            {{ $row['name'] }}
                        </a>
                        <span class="kt-badge kt-badge-sm {{ $chip['tone'] }}">
                            <i class="ki-filled {{ $row['state'] === \App\Reports\MeterCaseBoard::STATE_DECISION ? 'ki-information-2' : 'ki-time' }}"></i>
                            {{ __($chip['key']) }}
                        </span>
                        @if ($row['cycle_read'])
                            <span class="kt-badge kt-badge-xs kt-badge-outline">
                                <i class="ki-filled ki-check"></i>
                                {{ __('mc.chip_cycle_in') }}
                            </span>
                        @endif
                        <span class="grow"></span>
                        @if ($row['outstanding'] > 0)
                            <span class="amount-cell text-sm font-semibold text-destructive">{{ money($row['outstanding']) }}</span>
                        @endif
                    </div>

                    <p class="text-xs text-muted-foreground">
                        {{ collect([
                            $row['route'],
                            $row['meter_no'] ? __('t.meter').' '.ltr_isolate($row['meter_no']) : null,
                            $row['amperage'] ? ltr_isolate($row['amperage'].'A') : null,
                            $row['phone'] ? ltr_isolate($row['phone']) : null,
                        ])->filter()->implode(' · ') }}
                    </p>

                    {{-- The case itself: since when, who saw it, and what they said in their words --}}
                    <div class="rounded-lg bg-muted p-3">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
                            <span class="font-semibold text-mono">{{ $report->caseLabel($row) }}</span>
                            <span class="text-muted-foreground">
                                {{ __('mc.reported_by') }}: {{ $row['reported_by'] ?? __('rdgb.no_user') }}
                            </span>
                            <span class="text-muted-foreground">
                                {{ plural('rdgb.months_ago', (int) $row['age']) }}
                            </span>
                        </div>
                        @if ($row['note'])
                            <p class="mt-1 text-sm text-secondary-foreground">«{{ $row['note'] }}»</p>
                        @endif
                    </div>

                    {{-- What the deciding admin needs in front of them. The average is a datum, not a
                         proposal: the office enters the estimated kWh themselves. --}}
                    <div class="grid grid-cols-2 gap-2 text-center text-2xs sm:grid-cols-4">
                        <div class="rounded-lg border border-border p-2">
                            <div class="text-muted-foreground">{{ __('mc.prev_value') }}</div>
                            <div class="mt-0.5 amount-cell text-sm font-semibold text-mono">{{ number_format($row['previous'], 0) }}</div>
                        </div>
                        <div class="rounded-lg border border-border p-2">
                            <div class="text-muted-foreground">{{ __('mc.last_real') }}</div>
                            <div class="mt-0.5 amount-cell text-sm font-semibold text-mono">
                                @if ($row['last_real_period'])
                                    {{ number_format((float) $row['last_real_consumption'], 0) }}
                                    <span class="text-2xs text-muted-foreground">kWh</span>
                                @else
                                    —
                                @endif
                            </div>
                        </div>
                        <div class="rounded-lg border border-border p-2">
                            <div class="text-muted-foreground">{{ __('mc.average') }}</div>
                            <div class="mt-0.5 amount-cell text-sm font-semibold text-mono">
                                {{ $row['average'] === null ? '—' : number_format($row['average'], 0) }}
                                @if ($row['average'] !== null)
                                    <span class="text-2xs text-muted-foreground">kWh</span>
                                @endif
                            </div>
                        </div>
                        <div class="rounded-lg border border-border p-2">
                            <div class="text-muted-foreground">{{ __('mc.estimates') }}</div>
                            <div class="mt-0.5 amount-cell text-sm font-semibold text-mono">{{ $row['estimates_used'] }}</div>
                        </div>
                    </div>

                    {{-- What is due next, in one sentence: the screen's whole reason to exist --}}
                    <p class="flex items-start gap-1.5 text-sm font-medium text-mono">
                        <i class="ki-filled ki-arrow-right mt-0.5 shrink-0 text-muted-foreground rtl:rotate-180"></i>
                        {{ $report->nextStepLabel($row) }}
                    </p>

                    {{-- The decisions. Every one of them is an existing action, so a case is closed
                         by the same code path a profile button would use. --}}
                    <div class="flex flex-wrap items-center gap-2" data-meter-case-actions="{{ $row['state'] }}">
                        <livewire:admin.action-form
                            :action="\App\Admin\Actions\RecordReading::class"
                            :subject-id="$row['id']"
                            :return-to="request()->fullUrl()"
                            :key="'case-read-'.$row['id']" />

                        <livewire:admin.action-form
                            :action="\App\Admin\Actions\ConvertToFlat::class"
                            :subject-id="$row['id']"
                            :return-to="request()->fullUrl()"
                            :key="'case-flat-'.$row['id']" />

                        @if ($row['state'] === \App\Reports\MeterCaseBoard::STATE_DECISION)
                            {{-- Only once the allowance is spent: this IS the exception, and offering
                                 it earlier would quietly become the policy. --}}
                            <span data-meter-case-extension class="contents">
                                <livewire:admin.action-form
                                    :action="\App\Admin\Actions\ExtendEstimate::class"
                                    :subject-id="$row['id']"
                                    :return-to="request()->fullUrl()"
                                    :key="'case-extend-'.$row['id']" />
                            </span>
                        @endif

                        <button type="button" wire:click="clearReport({{ $row['id'] }})"
                                wire:confirm="{{ __('mc.clear_confirm', ['name' => $row['name']]) }}"
                                class="kt-btn kt-btn-sm kt-btn-ghost">
                            <i class="ki-filled ki-check"></i>
                            {{ __('mc.clear_btn') }}
                        </button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
