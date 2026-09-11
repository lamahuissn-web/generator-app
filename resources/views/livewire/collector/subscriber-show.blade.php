<div class="grid gap-4">
    @php
        $sub = $this->subscriber;
        $waBill = ($currentBill && $sub->phone)
            ? \App\Services\WhatsAppService::waLink($sub->phone, \App\Services\WhatsAppService::receiptText($sub, $currentBill, null, $outstanding, 'ar'))
            : null;
        $waPlain = $sub->phone ? \App\Services\WhatsAppService::waLink($sub->phone, '') : null;
        $initials = str($sub->name)->trim()->explode(' ')->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
        $isRtl = app()->isLocale('ar');
    @endphp

    @if ($flash)
        <div class="kt-alert kt-alert-light {{ $flashType === 'warning' ? 'kt-alert-warning' : 'kt-alert-success' }}" role="status">
            <div class="kt-alert-icon">
                <i class="ki-filled {{ $flashType === 'warning' ? 'ki-information' : 'ki-check-circle' }} text-base"></i>
            </div>
            <div class="kt-alert-content">
                <div class="kt-alert-title">{{ $flash }}</div>
            </div>
            <div class="kt-alert-actions">
                <button type="button" wire:click="dismissFlash" class="kt-btn kt-btn-icon kt-btn-ghost kt-btn-sm">
                    <i class="ki-filled ki-cross"></i>
                </button>
            </div>
        </div>
    @endif

    {{-- Subscriber header --}}
    <div class="kt-card">
        <div class="kt-card-content">
            <div class="flex items-start gap-3">
                <span class="tile tile-primary size-12 text-base font-semibold">
                    {{ $initials ?: '?' }}
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <h1 class="text-lg font-semibold leading-tight text-mono">{{ $sub->name }}</h1>
                        <span class="kt-badge kt-badge-sm {{ $sub->type === 'metered' ? 'kt-badge-info' : 'kt-badge-secondary' }}">
                            {{ $sub->type === 'metered' ? __('portal.metered') : __('portal.flat_rate') }}
                        </span>
                        @if ($sub->status === 'inactive')
                            <span class="kt-badge kt-badge-sm kt-badge-outline">{{ __('portal.inactive') }}</span>
                        @else
                            <span class="kt-badge kt-badge-sm kt-badge-success">{{ __('portal.active') }}</span>
                        @endif
                    </div>

                    <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-muted-foreground sm:grid-cols-3">
                        @if ($sub->route)
                            <span class="flex items-center gap-1">
                                <i class="ki-filled ki-map"></i>{{ $sub->route->name }}
                            </span>
                        @endif
                        @if ($sub->meter_no)
                            <span class="flex items-center gap-1">
                                <i class="ki-filled ki-bill"></i>
                                <span dir="ltr">{{ $sub->meter_no }}</span>
                            </span>
                        @endif
                        @if ($sub->amperage)
                            <span class="flex items-center gap-1">
                                <i class="ki-filled ki-flash-circle"></i>
                                <span dir="ltr">{{ $sub->amperage }} A</span>
                            </span>
                        @endif
                        @if ($sub->address)
                            <span class="col-span-2 flex items-center gap-1 sm:col-span-3">
                                <i class="ki-filled ki-geolocation"></i>{{ $sub->address }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            @if ($sub->phone)
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <a href="tel:{{ preg_replace('/\s+/', '', $sub->phone) }}" class="kt-btn kt-btn-outline justify-center">
                        <i class="ki-filled ki-phone"></i>
                        <span dir="ltr">{{ $sub->phone }}</span>
                    </a>
                    @if ($waPlain)
                        <a href="{{ $waPlain }}" target="_blank" rel="noopener" class="kt-btn justify-center">
                            @include('collector.partials.wa-icon')
                            {{ __('portal.whatsapp') }}
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if ($sub->status === 'inactive')
        <div class="kt-alert kt-alert-light kt-alert-destructive" role="status">
            <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
            <div class="kt-alert-content">
                <div class="kt-alert-title">{{ __('portal.inactive_blocked') }}</div>
            </div>
        </div>
    @endif

    {{-- Balance --}}
    <div class="kt-card">
        <div class="kt-card-content flex items-center justify-between gap-3">
            <div class="flex flex-col gap-1">
                <span class="text-2xs font-semibold uppercase tracking-widest text-muted-foreground">{{ __('portal.outstanding') }}</span>
                <span class="amount-cell text-3xl font-semibold {{ $outstanding > 0 ? 'text-destructive' : 'text-green-600' }}">
                    {{ money($outstanding) }}
                </span>
                <span class="text-xs text-muted-foreground">
                    @if ($outstanding > 0)
                        {{ __('portal.balance_due') }}
                    @else
                        {{ __('portal.balance_clear') }}
                    @endif
                </span>
            </div>
            <div class="flex shrink-0 flex-col items-end gap-1.5">
                <span class="kt-badge kt-badge-lg {{ $outstanding > 0 ? 'kt-badge-destructive' : 'kt-badge-success' }}">
                    <i class="ki-filled {{ $outstanding > 0 ? 'ki-dollar' : 'ki-check-circle' }}"></i>
                    {{ $outstanding > 0 ? __('portal.due') : __('portal.settled') }}
                </span>
                <span class="text-2xs text-muted-foreground">{{ period_label($period) }} · kWh {{ money($price) }}</span>
            </div>
        </div>
    </div>

    {{-- View switcher: the active view is the only solid button, so which panel is
         on screen is never ambiguous. --}}
    <div class="grid grid-cols-3 gap-1.5 rounded-lg bg-muted p-1.5">
        @foreach (['overview' => __('portal.overview'), 'reading' => __('portal.reading'), 'payment' => __('portal.collect')] as $key => $label)
            <button wire:click="switchView('{{ $key }}')"
                    class="kt-btn kt-btn-sm justify-center {{ $view === $key ? '' : 'kt-btn-ghost' }}">
                <i class="ki-filled {{ ['overview' => 'ki-chart-pie-3', 'reading' => 'ki-bill', 'payment' => 'ki-wallet'][$key] }}"></i>
                <span>{{ $label }}</span>
                @if ($key === 'reading' && $currentBill)
                    <span class="size-1.5 rounded-full bg-green-500"></span>
                @endif
                @if ($key === 'payment' && $outstanding > 0)
                    <span class="size-1.5 rounded-full bg-destructive"></span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- ============ OVERVIEW ============ --}}
    @if ($view === 'overview')
        @if ($currentBill)
            <div class="kt-card">
                <div class="kt-card-header">
                    <div class="kt-card-heading">
                        <h3 class="kt-card-title">{{ period_label($currentBill->period) }} {{ __('mdl.bill') }}</h3>
                        <span class="kt-card-description" dir="ltr">{{ $currentBill->number }}</span>
                    </div>
                    @php $st = $currentBill->paymentStatus(); @endphp
                    <span class="kt-badge kt-badge-sm {{ $st === 'paid' ? 'kt-badge-success' : ($st === 'partial' ? 'kt-badge-outline kt-badge-warning' : 'kt-badge-destructive') }}">
                        {{ __('portal.'.$st) }}
                    </span>
                </div>
                <div class="kt-card-content grid gap-2.5 py-4 text-sm">
                    @if ($currentBill->type === 'metered')
                        <div class="flex justify-between gap-3 text-secondary-foreground">
                            <span>{{ __('portal.consumption') }}</span>
                            <span class="amount-cell font-semibold text-mono">{{ number_format((float) $currentBill->consumption, 1) }} kWh</span>
                        </div>
                        @if ((float) $currentBill->usage_amount > 0)
                            <div class="flex justify-between gap-3 text-secondary-foreground">
                                <span>{{ __('portal.usage_kwh', ['price' => money($currentBill->kwh_price)]) }}</span>
                                <span class="amount-cell font-semibold text-mono">{{ money($currentBill->usage_amount) }}</span>
                            </div>
                        @endif
                    @else
                        <div class="flex justify-between gap-3 text-secondary-foreground">
                            <span>{{ __('portal.flat_fee') }}</span>
                            <span class="amount-cell font-semibold text-mono">{{ money($currentBill->flat_amount) }}</span>
                        </div>
                    @endif

                    @if ((float) $currentBill->maintenance_fee > 0)
                        <div class="flex justify-between gap-3 text-secondary-foreground">
                            <span>{{ __('portal.maintenance', ['a' => $sub->amperage ?: '']) }}</span>
                            <span class="amount-cell font-semibold text-mono">{{ money($currentBill->maintenance_fee) }}</span>
                        </div>
                    @endif

                    @if ($currentBill->reading?->is_estimated)
                        <span class="kt-badge kt-badge-sm kt-badge-outline kt-badge-warning">
                            <i class="ki-filled ki-information"></i>
                            {{ __('portal.reading_estimated_badge') }}
                        </span>
                    @endif
                    @if ($currentBill->reading?->hasMeterSwap())
                        <span class="kt-badge kt-badge-sm kt-badge-info">
                            <i class="ki-filled ki-arrows-circle"></i>
                            {{ __('t.meter_swap') }}: <span dir="ltr">{{ $currentBill->reading->meterSwapLabel() }}</span>
                        </span>
                    @endif

                    <div class="mt-1 border-t border-border pt-2.5">
                        <div class="flex justify-between gap-3 font-semibold text-mono">
                            <span>{{ __('portal.total_bill') }}</span>
                            <span class="amount-cell">{{ money($currentBill->total) }}</span>
                        </div>
                    </div>

                    @if ((float) $currentBill->prev_debt > 0)
                        <div class="flex justify-between gap-3 text-destructive">
                            <span>{{ __('portal.prev_debt_roll') }}</span>
                            <span class="amount-cell font-semibold">{{ money($currentBill->prev_debt) }}</span>
                        </div>
                    @endif
                    @if ((float) $currentBill->paid_amount > 0)
                        <div class="flex justify-between gap-3 text-green-600">
                            <span>{{ __('portal.paid_lbl') }}</span>
                            <span class="amount-cell font-semibold">− {{ money($currentBill->paid_amount) }}</span>
                        </div>
                    @endif

                    @if ($outstanding > 0)
                        <button wire:click="switchView('payment')" class="kt-btn kt-btn-lg mt-2 w-full justify-center">
                            <i class="ki-filled ki-wallet"></i>
                            {{ __('portal.collect_btn') }}
                        </button>
                    @endif
                </div>
            </div>
        @elseif ($sub->isMetered())
            <div class="kt-card border-dashed">
                <div class="kt-card-content flex flex-col items-center gap-2.5 text-center">
                    <span class="tile tile-muted size-12 text-xl">
                        <i class="ki-filled ki-bill"></i>
                    </span>
                    <span class="font-semibold text-mono">{{ __('portal.no_reading_yet', ['period' => period_label($period)]) }}</span>
                    <span class="text-sm text-muted-foreground">{{ __('portal.no_reading_body') }}</span>
                    <button wire:click="switchView('reading')" class="kt-btn kt-btn-lg mt-1 w-full justify-center">
                        <i class="ki-filled ki-plus"></i>
                        {{ __('portal.take_reading') }}
                    </button>
                </div>
            </div>
        @else
            <div class="kt-card border-dashed">
                <div class="kt-card-content flex flex-col items-center gap-2.5 text-center">
                    <span class="tile tile-muted size-12 text-xl">
                        <i class="ki-filled ki-dollar"></i>
                    </span>
                    <span class="font-semibold text-mono">{{ __('portal.flat_sub') }}</span>
                    <span class="text-sm text-muted-foreground">{{ __('portal.flat_fee_msg', ['fee' => money_ltr($sub->flat_fee)]) }}</span>
                    <button wire:click="switchView('payment')" class="kt-btn kt-btn-lg mt-1 w-full justify-center">
                        <i class="ki-filled ki-wallet"></i>
                        {{ __('portal.collect_btn') }}
                    </button>
                </div>
            </div>
        @endif

        @if ($recentBills->isNotEmpty() || $recentPayments->isNotEmpty())
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($recentBills->isNotEmpty())
                    <div class="kt-card">
                        <div class="kt-card-header">
                            <h3 class="kt-card-title">{{ __('portal.recent_bills') }}</h3>
                        </div>
                        <div class="kt-card-content py-1">
                            <ul class="divide-y divide-border">
                                @foreach ($recentBills as $b)
                                    @php $s = $b->paymentStatus(); @endphp
                                    <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                                        <span class="text-secondary-foreground">{{ period_label($b->period) }}</span>
                                        <span class="flex items-center gap-2">
                                            <span class="amount-cell font-semibold text-mono">{{ money($b->total) }}</span>
                                            <span class="size-2 rounded-full {{ $s === 'paid' ? 'bg-green-500' : ($s === 'partial' ? 'bg-yellow-400' : 'bg-destructive') }}"></span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                @if ($recentPayments->isNotEmpty())
                    <div class="kt-card">
                        <div class="kt-card-header">
                            <h3 class="kt-card-title">{{ __('portal.recent_payments') }}</h3>
                        </div>
                        <div class="kt-card-content py-1">
                            <ul class="divide-y divide-border">
                                @foreach ($recentPayments as $p)
                                    <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                                        <span class="text-muted-foreground">{{ $p->created_at->format('M j, H:i') }}</span>
                                        <span class="flex items-center gap-2">
                                            <span class="amount-cell font-semibold text-green-600">{{ money($p->amount) }}</span>
                                            <span class="kt-badge kt-badge-xs kt-badge-outline">{{ $p->collector?->name }}</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    @endif

    {{-- ============ READING ============ --}}
    @if ($view === 'reading')
        @if (! $sub->isMetered())
            <div class="kt-card">
                <div class="kt-card-content flex flex-col items-center gap-2.5 text-center">
                    <span class="tile tile-muted size-12 text-xl">
                        <i class="ki-filled ki-dollar"></i>
                    </span>
                    <span class="font-semibold text-mono">{{ __('portal.no_meter_needed') }}</span>
                    <span class="text-sm text-muted-foreground">{{ __('portal.no_meter_body', ['fee' => money_ltr($sub->flat_fee)]) }}</span>
                    <button wire:click="switchView('payment')" class="kt-btn kt-btn-lg mt-1 w-full justify-center">
                        <i class="ki-filled ki-wallet"></i>
                        {{ __('portal.go_collection') }}
                    </button>
                </div>
            </div>
        @elseif ($currentBill)
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">{{ __('portal.reading_recorded', ['period' => period_label($currentBill->period)]) }}</h3>
                    <span class="kt-badge kt-badge-sm kt-badge-success">
                        <i class="ki-filled ki-check"></i>
                        {{ __('portal.done') }}
                    </span>
                </div>
                <div class="kt-card-content grid gap-2.5 py-4 text-sm">
                    <div class="flex justify-between gap-3 text-secondary-foreground">
                        <span>{{ __('portal.prev_reading') }}</span>
                        <span class="amount-cell font-semibold text-mono">{{ number_format((float) $currentBill->reading?->previous_reading, 0) }}</span>
                    </div>
                    <div class="flex justify-between gap-3 text-secondary-foreground">
                        <span>{{ __('portal.cur_reading') }}</span>
                        <span class="amount-cell font-semibold text-mono">{{ number_format((float) $currentBill->reading?->current_reading, 0) }}</span>
                    </div>
                    <div class="flex justify-between gap-3 text-secondary-foreground">
                        <span>{{ __('portal.consumption') }}</span>
                        <span class="amount-cell font-semibold text-mono">{{ number_format((float) $currentBill->consumption, 1) }} kWh</span>
                    </div>

                    @if ($currentBill->reading?->is_estimated)
                        <span class="kt-badge kt-badge-sm kt-badge-outline kt-badge-warning">
                            <i class="ki-filled ki-information"></i>
                            {{ __('portal.reading_estimated_badge') }}
                        </span>
                    @endif
                    @if ($currentBill->reading?->hasMeterSwap())
                        <span class="kt-badge kt-badge-sm kt-badge-info">
                            <i class="ki-filled ki-arrows-circle"></i>
                            {{ __('t.meter_swap') }}: <span dir="ltr">{{ $currentBill->reading->meterSwapLabel() }}</span>
                        </span>
                    @endif

                    <div class="mt-1 flex justify-between gap-3 border-t border-border pt-2.5 font-semibold text-mono">
                        <span>{{ __('portal.bill_total_incl') }}</span>
                        <span class="amount-cell">{{ money($currentBill->total + $currentBill->prev_debt) }}</span>
                    </div>

                    <div class="mt-2 grid gap-2">
                        @if ($outstanding > 0)
                            <button wire:click="switchView('payment')" class="kt-btn kt-btn-lg w-full justify-center">
                                <i class="ki-filled ki-wallet"></i>
                                {{ __('portal.collect_btn') }}
                            </button>
                        @endif
                        @if ($waBill)
                            <a href="{{ $waBill }}" target="_blank" rel="noopener" class="kt-btn kt-btn-lg kt-btn-outline w-full justify-center">
                                @include('collector.partials.wa-icon')
                                {{ __('portal.send_bill_wa') }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @else
            @if ($sub->hasBrokenMeterReport())
                <div class="kt-alert kt-alert-light kt-alert-warning">
                    <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                    <div class="kt-alert-content">
                        <div class="kt-alert-title">{{ __('portal.broken_banner_title') }}</div>
                        <div class="kt-alert-description">
                            {{ __('portal.broken_banner_body', ['since' => $sub->meter_broken_reported_at?->diffForHumans()]) }}
                        </div>
                        @if ($sub->meter_broken_note)
                            <div class="kt-alert-description">«{{ $sub->meter_broken_note }}»</div>
                        @endif
                    </div>
                    <div class="kt-alert-actions">
                        <button wire:click="cancelBrokenReport" class="kt-btn kt-btn-sm kt-btn-outline shrink-0">
                            {{ __('portal.broken_cancel') }}
                        </button>
                    </div>
                </div>
            @endif

            <div class="kt-card">
                <div class="kt-card-content">
                    <h3 class="kt-card-title">{{ __('portal.enter_reading') }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ __('portal.period_prev', ['period' => period_label($period)]) }}
                        <span class="amount-cell font-semibold text-mono">{{ number_format($prevReading, 0) }}</span>
                    </p>

                    <div class="mt-4">
                        <input type="text" inputmode="decimal" wire:model.live.debounce.350ms="readingInput"
                               placeholder="{{ __('portal.reading_ph') }}"
                               class="kt-input h-auto py-3 text-center text-2xl font-semibold tabular-nums">
                        @error('readingInput') <p class="mt-1.5 text-sm font-medium text-destructive">{{ $message }}</p> @enderror
                        @error('reading') <p class="mt-1.5 text-sm font-medium text-destructive">{{ $message }}</p> @enderror
                    </div>

                    @if ($preview)
                        @if ($preview['error'])
                            <div class="kt-alert kt-alert-light kt-alert-destructive mt-4">
                                <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                                <div class="kt-alert-content">
                                    <div class="kt-alert-title">{{ $preview['error'] }}</div>
                                </div>
                            </div>
                        @else
                            <div class="mt-4 grid gap-2 rounded-lg bg-muted p-4 text-sm">
                                <div class="flex justify-between gap-3">
                                    <span class="text-muted-foreground">{{ __('portal.consumption') }}</span>
                                    <span class="amount-cell font-semibold text-mono">{{ number_format($preview['consumption'], 1) }} kWh</span>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <span class="text-muted-foreground">{{ __('portal.reading_preview_usage') }}</span>
                                    <span class="amount-cell text-secondary-foreground">{{ money($preview['usage']) }}</span>
                                </div>
                                @if ($preview['maintenance'] > 0)
                                    <div class="flex justify-between gap-3">
                                        <span class="text-muted-foreground">{{ __('portal.maintenance', ['a' => $sub->amperage ?: '']) }}</span>
                                        <span class="amount-cell text-secondary-foreground">{{ money($preview['maintenance']) }}</span>
                                    </div>
                                @endif
                                @if ($preview['debt'] > 0)
                                    <div class="flex justify-between gap-3">
                                        <span class="text-muted-foreground">{{ __('portal.prev_debt_lbl') }}</span>
                                        <span class="amount-cell text-destructive">{{ money($preview['debt']) }}</span>
                                    </div>
                                @endif
                                <div class="mt-1 flex items-center justify-between gap-3 border-t border-border pt-2.5">
                                    <span class="font-semibold text-mono">{{ __('portal.due_now') }}</span>
                                    <span class="amount-cell text-lg font-semibold text-primary">{{ money($preview['due_now']) }}</span>
                                </div>
                            </div>
                            <button wire:click="saveReading" wire:loading.attr="disabled" wire:target="saveReading" class="kt-btn kt-btn-lg mt-4 w-full justify-center">
                                <i class="ki-filled ki-check"></i>
                                {{ __('portal.save_reading') }}
                            </button>
                        @endif
                    @endif

                    @error('broken') <p class="mt-3 text-sm font-medium text-destructive">{{ $message }}</p> @enderror

                    @unless ($sub->hasBrokenMeterReport())
                        @if ($showBrokenReport)
                            <div class="mt-4 grid gap-2.5 rounded-lg border border-yellow-400/40 bg-yellow-400/10 p-3.5">
                                <span class="text-xs font-semibold text-yellow-700 dark:text-yellow-600">{{ __('portal.broken_form_title') }}</span>
                                <input type="text" wire:model="brokenNote" maxlength="200"
                                       placeholder="{{ __('portal.broken_note_ph') }}"
                                       class="kt-input kt-input-sm bg-background">
                                <div class="grid grid-cols-2 gap-2">
                                    <button wire:click="reportBrokenMeter" class="kt-btn kt-btn-sm justify-center">
                                        {{ __('portal.broken_send') }}
                                    </button>
                                    <button wire:click="toggleBrokenReport" class="kt-btn kt-btn-sm kt-btn-outline justify-center">
                                        {{ __('portal.broken_dismiss') }}
                                    </button>
                                </div>
                            </div>
                        @else
                            <button wire:click="toggleBrokenReport"
                                    class="kt-btn kt-btn-sm kt-btn-outline kt-btn-destructive mt-3 w-full justify-center">
                                <i class="ki-filled ki-information"></i>
                                {{ __('portal.broken_btn') }}
                            </button>
                        @endif
                    @endunless
                </div>
            </div>
        @endif
    @endif

    {{-- ============ PAYMENT ============ --}}
    @if ($view === 'payment')
        @error('payment')
            <div class="kt-alert kt-alert-light kt-alert-destructive">
                <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                <div class="kt-alert-content">
                    <div class="kt-alert-title">{{ $message }}</div>
                </div>
            </div>
        @enderror

        @if ($outstanding <= 0 && ! $receipt)
            <div class="kt-card">
                <div class="kt-card-content flex flex-col items-center gap-2.5 text-center">
                    <span class="tile tile-success size-12 text-xl">
                        <i class="ki-filled ki-check-circle"></i>
                    </span>
                    <span class="font-semibold text-mono">{{ __('portal.no_balance_title') }}</span>
                    <span class="text-sm text-muted-foreground">{{ __('portal.no_balance_body') }}</span>
                </div>
            </div>
        @elseif ($receipt)
            <div class="kt-card overflow-hidden">
                <div class="bg-green-600 px-5 py-4 text-white">
                    <div class="flex items-center justify-between gap-2.5">
                        <span class="text-2xs font-semibold uppercase tracking-widest text-green-50">{{ __('portal.payment_received') }}</span>
                        <span class="kt-badge kt-badge-sm bg-white/20 text-white">{{ $receipt['method_label'] }}</span>
                    </div>
                    <p class="amount-cell mt-1.5 text-3xl font-semibold">{{ money($receipt['applied']) }}</p>
                    @if ($receipt['excess'] > 0)
                        <p class="text-xs text-green-50">{{ __('portal.excess_msg', ['amount' => money_ltr($receipt['excess'])]) }}</p>
                    @endif
                </div>

                <div class="kt-card-content grid gap-2.5 text-sm">
                    <div class="flex justify-between gap-3">
                        <span class="text-secondary-foreground">{{ __('portal.balance_remaining') }}</span>
                        <span class="amount-cell font-semibold {{ $receipt['remaining'] > 0 ? 'text-destructive' : 'text-green-600' }}">
                            {{ $receipt['remaining'] > 0 ? money($receipt['remaining']) : __('portal.fully_settled') }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ $receipt['number'] ?: __('portal.payment_no', ['id' => $lastPaymentId, 'period' => '']) }}
                        @if ($receipt['bill_period']) · {{ period_label($receipt['bill_period']) }} @endif
                    </p>

                    @if (! empty($receipt['remaining_note']))
                        <p class="rounded-lg bg-yellow-400/10 p-3 text-xs leading-relaxed text-yellow-700 ring-1 ring-yellow-400/20 dark:text-yellow-600">
                            {{ $receipt['remaining_note'] }}
                        </p>
                    @endif

                    @if (! empty($receipt['settled']))
                        <div class="rounded-lg bg-muted p-3">
                            <p class="mb-1.5 text-2xs font-semibold uppercase tracking-wide text-muted-foreground">{{ __('portal.applied_to') }}</p>
                            <ul class="divide-y divide-border">
                                @foreach ($receipt['settled'] as $settledRow)
                                    <li class="flex items-center justify-between gap-3 py-1.5 text-xs">
                                        <span class="text-muted-foreground">
                                            <span dir="ltr">{{ $settledRow['number'] }}</span> · {{ period_label($settledRow['period']) }}
                                        </span>
                                        <span class="amount-cell font-semibold text-mono">{{ money($settledRow['amount']) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="mt-1 border-t border-border pt-3">
                        <p class="mb-2 text-center text-2xs font-semibold uppercase tracking-wide text-muted-foreground">{{ __('portal.send_receipt') }}</p>
                        <div class="grid gap-2">
                            @if ($lastPaymentId)
                                <a href="{{ route('print.receipt', ['payment' => $lastPaymentId, 'size' => '80mm']) }}" target="_blank" rel="noopener"
                                   class="kt-btn kt-btn-lg kt-btn-outline w-full justify-center">
                                    <i class="ki-filled ki-printer"></i>
                                    {{ __('prt.print_receipt') }}
                                </a>
                            @endif
                            @if ($receipt['wa_ar'])
                                <a href="{{ $receipt['wa_ar'] }}" target="_blank" rel="noopener" class="kt-btn kt-btn-lg w-full justify-center">
                                    @include('collector.partials.wa-icon')
                                    {{ __('portal.arabic_receipt') }}
                                </a>
                            @endif
                            @if ($receipt['wa_en'])
                                <a href="{{ $receipt['wa_en'] }}" target="_blank" rel="noopener" class="kt-btn kt-btn-lg kt-btn-outline w-full justify-center">
                                    @include('collector.partials.wa-icon')
                                    {{ __('portal.english_receipt') }}
                                </a>
                            @endif
                            <button wire:click="clearReceipt" class="kt-btn kt-btn-dim w-full justify-center">
                                {{ __('portal.record_another') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="kt-card">
                <div class="kt-card-content grid gap-4">
                    @if ($currentBill)
                        <p class="rounded-lg bg-muted px-3.5 py-2.5 text-sm text-secondary-foreground">
                            {{ __('portal.current_bill_line', ['type' => $currentBill->type === 'metered' ? __('portal.metered') : __('portal.flat_fee'), 'period' => period_label($currentBill->period)]) }}
                            <span class="font-semibold text-mono">{{ money($currentBill->total) }}</span>
                            @if ((float) $currentBill->prev_debt > 0)
                                + <span class="font-semibold text-destructive">{{ money($currentBill->prev_debt) }}</span> {{ __('portal.prev_debt_lbl') }}
                            @endif
                        </p>
                    @endif

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label font-normal text-mono">{{ __('portal.amount_received') }}</label>
                        <div class="kt-input kt-input-lg amount-cell font-semibold">
                            <span class="text-lg text-muted-foreground">$</span>
                            <input type="text" inputmode="decimal" wire:model="paymentAmount" placeholder="0.00"
                                   class="text-xl font-semibold">
                        </div>
                        @error('paymentAmount') <p class="text-sm font-medium text-destructive">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button wire:click="setFullAmount" class="kt-btn kt-btn-sm kt-btn-mono">
                            <i class="ki-filled ki-dollar"></i>
                            {{ __('portal.full_balance', ['amount' => money_ltr($outstanding)]) }}
                        </button>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label font-normal text-mono">{{ __('portal.method') }}</label>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            @foreach (\App\Models\Payment::$methods as $key => $label)
                                <button type="button" wire:click="$set('paymentMethod', '{{ $key }}')"
                                        class="kt-btn kt-btn-sm w-full justify-center {{ $paymentMethod === $key ? '' : 'kt-btn-outline' }}">
                                    {{ __("pay.{$key}") }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label font-normal text-mono">{{ __('t.note') }}</label>
                        <input type="text" wire:model="paymentNote" placeholder="{{ __('portal.note_ph') }}" class="kt-input">
                    </div>

                    <button wire:click="savePayment" wire:loading.attr="disabled" wire:target="savePayment" class="kt-btn kt-btn-lg w-full justify-center">
                        <i class="ki-filled ki-wallet"></i>
                        {{ __('portal.record_collection') }}
                    </button>
                </div>
            </div>
        @endif
    @endif

    {{-- Back --}}
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('collector.dashboard') }}"
       class="kt-btn kt-btn-dim w-full justify-center">
        <i class="ki-filled ki-arrow-left rtl:rotate-180"></i>
        {{ __('portal.back') }}
    </a>
</div>
