<div class="space-y-4">
    @php
        $sub = $this->subscriber;
        $waBill = ($currentBill && $sub->phone)
            ? \App\Services\WhatsAppService::waLink($sub->phone, \App\Services\WhatsAppService::receiptText($sub, $currentBill, null, $outstanding, 'ar'))
            : null;
        $waPlain = $sub->phone ? \App\Services\WhatsAppService::waLink($sub->phone, '') : null;
        $initials = str($sub->name)->trim()->explode(' ')->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
    @endphp

    @if ($flash)
        <div class="flex items-start justify-between gap-3 rounded-2xl border px-4 py-3 text-sm font-medium shadow-sm
            {{ $flashType === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
            <span>✅ {{ $flash }}</span>
            <button wire:click="dismissFlash" class="shrink-0 text-lg leading-none opacity-50 hover:opacity-100">×</button>
        </div>
    @endif

    {{-- Header card --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start gap-3">
            <span class="grid h-13 w-13 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-amber-400 to-amber-500 text-lg font-extrabold text-slate-900 shadow-sm" style="height:3.25rem;width:3.25rem">{{ $initials ?: '?' }}</span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                    <h1 class="text-lg font-bold leading-tight text-slate-900">{{ $sub->name }}</h1>
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $sub->type === 'metered' ? 'bg-sky-100 text-sky-700' : 'bg-violet-100 text-violet-700' }}">
                        {{ $sub->type === 'metered' ? 'Metered' : 'Flat rate' }}
                    </span>
                    @if ($sub->status === 'inactive')
                        <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600">Inactive</span>
                    @else
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-700">Active</span>
                    @endif
                </div>
                <div class="mt-1.5 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-slate-500 sm:grid-cols-3">
                    @if ($sub->route) <span>🗺️ {{ $sub->route->name }}</span> @endif
                    @if ($sub->meter_no) <span>🔢 Meter {{ $sub->meter_no }}</span> @endif
                    @if ($sub->amperage) <span>⚡ {{ $sub->amperage }} A</span> @endif
                    @if ($sub->address) <span class="col-span-2 sm:col-span-3">📍 {{ $sub->address }}</span> @endif
                </div>
            </div>
        </div>

        @if ($sub->phone)
            <div class="mt-4 grid grid-cols-2 gap-2">
                <a href="tel:{{ preg_replace('/\s+/', '', $sub->phone) }}"
                   class="flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-slate-50 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-300">
                    📞 Call
                </a>
                @if ($waPlain)
                    <a href="{{ $waPlain }}" target="_blank" rel="noopener"
                       class="flex items-center justify-center gap-2 rounded-xl bg-emerald-500 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.297-.497.1-.198.05-.371-.025-.52-.074-.149-.668-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        WhatsApp
                    </a>
                @endif
            </div>
        @endif
    </section>

    @if ($sub->status === 'inactive')
        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
            This subscriber is inactive — readings and collections are disabled.
        </div>
    @endif

    {{-- Balance hero --}}
    <section class="rounded-2xl border p-5 shadow-sm {{ $outstanding > 0 ? 'border-rose-200 bg-gradient-to-br from-rose-50 to-white' : 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-white' }}">
        <div class="flex items-center justify-between">
            <p class="text-[11px] font-bold uppercase tracking-widest text-slate-500">Outstanding balance</p>
            <span class="text-xs font-medium text-slate-400">{{ period_label($period) }} · kWh {{ money($price) }}</span>
        </div>
        <p class="mt-1.5 text-4xl font-extrabold tabular-nums tracking-tight {{ $outstanding > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
            {{ money($outstanding) }}
        </p>
        <p class="mt-1 text-xs text-slate-500">
            @if ($outstanding > 0)
                Includes unpaid bills from previous months.
            @else
                Fully settled — nothing due. 🎉
            @endif
        </p>
    </section>

    {{-- Segmented tabs --}}
    <nav class="grid grid-cols-3 gap-1 rounded-2xl bg-slate-200/70 p-1">
        @foreach (['overview' => 'Overview', 'reading' => 'Reading', 'payment' => 'Collect'] as $key => $label)
            <button wire:click="switchView('{{ $key }}')"
                    class="rounded-xl py-2.5 text-sm font-bold transition {{ $view === $key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                {{ $label }}
                @if ($key === 'reading' && $currentBill)
                    <span class="align-middle text-emerald-500">●</span>
                @endif
                @if ($key === 'payment' && $outstanding > 0)
                    <span class="align-middle text-rose-500">●</span>
                @endif
            </button>
        @endforeach
    </nav>

    {{-- ============ OVERVIEW ============ --}}
    @if ($view === 'overview')
        @if ($currentBill)
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-slate-900">{{ period_label($currentBill->period) }} bill</h3>
                    @php $st = $currentBill->paymentStatus(); @endphp
                    <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide {{ $st === 'paid' ? 'bg-emerald-100 text-emerald-700' : ($st === 'partial' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700') }}">
                        {{ $st }}
                    </span>
                </div>
                <dl class="mt-3 space-y-1.5 text-sm">
                    @if ($currentBill->type === 'metered')
                        <div class="flex justify-between text-slate-600"><dt>Consumption</dt><dd class="font-semibold tabular-nums">{{ number_format((float) $currentBill->consumption, 1) }} kWh</dd></div>
                        @if ((float) $currentBill->usage_amount > 0)
                            <div class="flex justify-between text-slate-600"><dt>Usage ({{ money($currentBill->kwh_price) }}/kWh)</dt><dd class="font-semibold tabular-nums">{{ money($currentBill->usage_amount) }}</dd></div>
                        @endif
                    @else
                        <div class="flex justify-between text-slate-600"><dt>Flat fee</dt><dd class="font-semibold tabular-nums">{{ money($currentBill->flat_amount) }}</dd></div>
                    @endif
                    @if ((float) $currentBill->maintenance_fee > 0)
                        <div class="flex justify-between text-slate-600"><dt>Maintenance{{ $sub->amperage ? ' ('.$sub->amperage.'A)' : '' }}</dt><dd class="font-semibold tabular-nums">{{ money($currentBill->maintenance_fee) }}</dd></div>
                    @endif
                    <div class="border-t border-dashed border-slate-200 pt-2"></div>
                    <div class="flex justify-between font-bold text-slate-900"><dt>Total bill</dt><dd class="tabular-nums">{{ money($currentBill->total) }}</dd></div>
                    @if ((float) $currentBill->prev_debt > 0)
                        <div class="flex justify-between text-rose-600"><dt>Previous debt rolled over</dt><dd class="font-semibold tabular-nums">{{ money($currentBill->prev_debt) }}</dd></div>
                    @endif
                    @if ((float) $currentBill->paid_amount > 0)
                        <div class="flex justify-between text-emerald-600"><dt>Paid</dt><dd class="font-semibold tabular-nums">− {{ money($currentBill->paid_amount) }}</dd></div>
                    @endif
                </dl>
                @if ($outstanding > 0)
                    <button wire:click="switchView('payment')" class="mt-4 w-full rounded-xl bg-emerald-600 py-3 font-bold text-white shadow-sm transition hover:bg-emerald-700">
                        Collect payment
                    </button>
                @endif
            </section>
        @elseif ($sub->isMetered())
            <section class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center shadow-sm">
                <p class="text-3xl">🔢</p>
                <p class="mt-2 font-bold text-slate-800">No reading taken for {{ period_label($period) }} yet</p>
                <p class="mt-1 text-sm text-slate-500">Record the meter reading to generate this month's bill.</p>
                <button wire:click="switchView('reading')" class="mt-4 w-full rounded-xl bg-sky-600 py-3 font-bold text-white shadow-sm transition hover:bg-sky-700">
                    Take reading
                </button>
            </section>
        @else
            <section class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center shadow-sm">
                <p class="text-3xl">🪙</p>
                <p class="mt-2 font-bold text-slate-800">Flat-rate subscriber</p>
                <p class="mt-1 text-sm text-slate-500">Collect the monthly flat fee of {{ money($sub->flat_fee) }}.</p>
                <button wire:click="switchView('payment')" class="mt-4 w-full rounded-xl bg-emerald-600 py-3 font-bold text-white shadow-sm transition hover:bg-emerald-700">
                    Collect payment
                </button>
            </section>
        @endif

        @if ($recentBills->isNotEmpty() || $recentPayments->isNotEmpty())
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($recentBills->isNotEmpty())
                    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Recent bills</h3>
                        <ul class="space-y-2">
                            @foreach ($recentBills as $b)
                                @php $s = $b->paymentStatus(); @endphp
                                <li class="flex items-center justify-between text-sm">
                                    <span class="text-slate-600">{{ period_label($b->period) }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="font-semibold tabular-nums text-slate-900">{{ money($b->total) }}</span>
                                        <span class="h-2 w-2 rounded-full {{ $s === 'paid' ? 'bg-emerald-500' : ($s === 'partial' ? 'bg-amber-400' : 'bg-rose-500') }}"></span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                @if ($recentPayments->isNotEmpty())
                    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Recent payments</h3>
                        <ul class="space-y-2">
                            @foreach ($recentPayments as $p)
                                <li class="flex items-center justify-between text-sm">
                                    <span class="text-slate-600">{{ $p->created_at->format('M j, H:i') }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="font-semibold tabular-nums text-emerald-700">{{ money($p->amount) }}</span>
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">{{ $p->collector?->name }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        @endif
    @endif

    {{-- ============ READING ============ --}}
    @if ($view === 'reading')
        @if (! $sub->isMetered())
            <section class="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
                <p class="text-3xl">🪙</p>
                <p class="mt-2 font-bold text-slate-800">No meter reading needed</p>
                <p class="mt-1 text-sm text-slate-500">This subscriber pays a fixed flat rate of {{ money($sub->flat_fee) }} per month.</p>
                <button wire:click="switchView('payment')" class="mt-4 w-full rounded-xl bg-emerald-600 py-3 font-bold text-white shadow-sm transition hover:bg-emerald-700">
                    Go to collection
                </button>
            </section>
        @elseif ($currentBill)
            <section class="rounded-2xl border border-emerald-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-slate-900">{{ period_label($currentBill->period) }} reading recorded</h3>
                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-bold uppercase text-emerald-700">Done ✅</span>
                </div>
                <dl class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between text-slate-600"><dt>Previous reading</dt><dd class="font-semibold tabular-nums">{{ number_format((float) $currentBill->reading?->previous_reading, 0) }}</dd></div>
                    <div class="flex justify-between text-slate-600"><dt>Current reading</dt><dd class="font-semibold tabular-nums">{{ number_format((float) $currentBill->reading?->current_reading, 0) }}</dd></div>
                    <div class="flex justify-between text-slate-600"><dt>Consumption</dt><dd class="font-semibold tabular-nums">{{ number_format((float) $currentBill->consumption, 1) }} kWh</dd></div>
                    <div class="border-t border-dashed border-slate-200 pt-2"></div>
                    <div class="flex justify-between font-bold text-slate-900"><dt>Bill total (incl. rolled-over debt)</dt><dd class="tabular-nums">{{ money($currentBill->total + $currentBill->prev_debt) }}</dd></div>
                </dl>
                <div class="mt-4 grid gap-2">
                    @if ($outstanding > 0)
                        <button wire:click="switchView('payment')" class="w-full rounded-xl bg-emerald-600 py-3 font-bold text-white shadow-sm transition hover:bg-emerald-700">
                            Collect payment
                        </button>
                    @endif
                    @if ($waBill)
                        <a href="{{ $waBill }}" target="_blank" rel="noopener"
                           class="flex w-full items-center justify-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 py-3 font-bold text-emerald-700 transition hover:bg-emerald-100">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.297-.497.1-.198.05-.371-.025-.52-.074-.149-.668-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            Send bill on WhatsApp
                        </a>
                    @endif
                </div>
            </section>
        @else
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-900">Enter current reading</h3>
                <p class="mt-0.5 text-sm text-slate-500">
                    {{ period_label($period) }} — previous reading:
                    <span class="font-bold tabular-nums text-slate-800">{{ number_format($prevReading, 0) }}</span>
                </p>

                <div class="mt-4">
                    <input type="text" inputmode="decimal" wire:model.live.debounce.350ms="readingInput"
                           placeholder="e.g. 1250"
                           class="w-full rounded-2xl border-0 bg-slate-100 px-4 py-4 text-center text-3xl font-extrabold tabular-nums ring-1 ring-transparent transition placeholder:text-base placeholder:font-normal placeholder:text-slate-400 focus:bg-white focus:ring-2 focus:ring-amber-400 focus:outline-none">
                    @error('readingInput') <p class="mt-1.5 text-sm font-medium text-rose-600">{{ $message }}</p> @enderror
                    @error('reading') <p class="mt-1.5 text-sm font-medium text-rose-600">{{ $message }}</p> @enderror
                </div>

                @if ($preview)
                    @if ($preview['error'])
                        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">{{ $preview['error'] }}</div>
                    @else
                        <div class="mt-4 rounded-2xl bg-slate-900 p-4 text-sm text-slate-200">
                            <div class="flex justify-between"><span class="text-slate-400">Consumption</span><span class="font-bold tabular-nums">{{ number_format($preview['consumption'], 1) }} kWh</span></div>
                            <div class="mt-1.5 flex justify-between"><span class="text-slate-400">Usage</span><span class="tabular-nums">{{ money($preview['usage']) }}</span></div>
                            @if ($preview['maintenance'] > 0)
                                <div class="mt-1.5 flex justify-between"><span class="text-slate-400">Maintenance</span><span class="tabular-nums">{{ money($preview['maintenance']) }}</span></div>
                            @endif
                            @if ($preview['debt'] > 0)
                                <div class="mt-1.5 flex justify-between"><span class="text-slate-400">Previous debt</span><span class="tabular-nums text-rose-300">{{ money($preview['debt']) }}</span></div>
                            @endif
                            <div class="mt-2.5 flex justify-between border-t border-slate-700 pt-2.5 text-base font-extrabold text-amber-300">
                                <span>Due now</span><span class="tabular-nums">{{ money($preview['due_now']) }}</span>
                            </div>
                        </div>
                        <button wire:click="saveReading" wire:loading.attr="disabled"
                                class="mt-4 w-full rounded-xl bg-sky-600 py-3.5 font-bold text-white shadow-sm transition hover:bg-sky-700 active:scale-[0.99]">
                            Save reading &amp; generate bill
                        </button>
                    @endif
                @endif
            </section>
        @endif
    @endif

    {{-- ============ PAYMENT ============ --}}
    @if ($view === 'payment')
        @error('payment') <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">{{ $message }}</div> @enderror

        @if ($outstanding <= 0 && ! $receipt)
            <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-center shadow-sm">
                <p class="text-3xl">🎉</p>
                <p class="mt-2 font-bold text-emerald-800">No outstanding balance</p>
                <p class="mt-1 text-sm text-emerald-700">This subscriber has nothing due right now.</p>
            </section>
        @elseif ($receipt)
            <section class="overflow-hidden rounded-2xl border border-emerald-300 bg-white shadow-md">
                <div class="bg-gradient-to-r from-emerald-600 to-emerald-500 px-5 py-4 text-white">
                    <div class="flex items-center justify-between">
                        <p class="text-[11px] font-bold uppercase tracking-widest text-emerald-100">Payment received</p>
                        <span class="rounded-full bg-white/20 px-2 py-0.5 text-[10px] font-bold uppercase">Cash</span>
                    </div>
                    <p class="mt-1 text-3xl font-extrabold tabular-nums">{{ money($receipt['applied']) }}</p>
                    @if ($receipt['excess'] > 0)
                        <p class="text-xs text-emerald-100">Excess {{ money($receipt['excess']) }} not applied</p>
                    @endif
                </div>
                <div class="space-y-2 px-5 py-4 text-sm">
                    <div class="flex justify-between text-slate-600">
                        <span>Balance remaining</span>
                        <span class="font-bold tabular-nums {{ $receipt['remaining'] > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                            {{ $receipt['remaining'] > 0 ? money($receipt['remaining']) : 'Fully settled 🎉' }}
                        </span>
                    </div>
                    <p class="text-xs text-slate-400">Payment #{{ $lastPaymentId }} · {{ $receipt['bill_period'] ? period_label($receipt['bill_period']) : '' }}</p>

                    <div class="border-t border-dashed border-slate-200 pt-3">
                        <p class="mb-2 text-center text-xs font-bold uppercase tracking-wide text-slate-500">Send receipt via WhatsApp</p>
                        <div class="grid gap-2">
                            @if ($receipt['wa_ar'])
                                <a href="{{ $receipt['wa_ar'] }}" target="_blank" rel="noopener"
                                   class="flex items-center justify-center gap-2 rounded-xl bg-emerald-500 py-3 font-bold text-white shadow-sm transition hover:bg-emerald-600">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.297-.497.1-.198.05-.371-.025-.52-.074-.149-.668-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                    Arabic receipt
                                </a>
                            @endif
                            @if ($receipt['wa_en'])
                                <a href="{{ $receipt['wa_en'] }}" target="_blank" rel="noopener"
                                   class="flex items-center justify-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 py-2.5 text-sm font-bold text-emerald-700 transition hover:bg-emerald-100">
                                    English receipt
                                </a>
                            @endif
                            <button wire:click="clearReceipt" class="rounded-xl py-2 text-sm font-semibold text-slate-500 transition hover:text-slate-800">
                                Record another payment
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        @else
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                @if ($currentBill)
                    <p class="mb-3 rounded-xl bg-slate-50 px-3 py-2.5 text-sm text-slate-600 ring-1 ring-slate-100">
                        {{ $currentBill->type === 'metered' ? 'Bill' : 'Flat fee' }} for {{ period_label($currentBill->period) }}:
                        <span class="font-bold text-slate-900">{{ money($currentBill->total) }}</span>
                        @if ((float) $currentBill->prev_debt > 0)
                            + <span class="font-semibold text-rose-600">{{ money($currentBill->prev_debt) }}</span> previous debt
                        @endif
                    </p>
                @endif

                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">Amount received</label>
                <div class="flex items-center overflow-hidden rounded-2xl bg-slate-100 ring-1 ring-transparent transition focus-within:bg-white focus-within:ring-2 focus-within:ring-amber-400">
                    <span class="pl-4 text-lg font-bold text-slate-400">$</span>
                    <input type="text" inputmode="decimal" wire:model="paymentAmount"
                           placeholder="0.00"
                           class="w-full flex-1 bg-transparent px-2 py-4 text-2xl font-extrabold tabular-nums focus:outline-none placeholder:text-slate-300">
                </div>
                @error('paymentAmount') <p class="mt-1.5 text-sm font-medium text-rose-600">{{ $message }}</p> @enderror

                <div class="mt-3 flex flex-wrap gap-2">
                    <button wire:click="setFullAmount"
                            class="rounded-full bg-slate-900 px-4 py-2 text-sm font-bold text-white transition hover:bg-slate-700">
                        Full balance — {{ money($outstanding) }}
                    </button>
                </div>

                <div class="mt-4">
                    <input type="text" wire:model="paymentNote" placeholder="Note (optional) — e.g. part payment"
                           class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 text-sm ring-1 ring-transparent transition focus:bg-white focus:ring-2 focus:ring-amber-400 focus:outline-none">
                </div>

                <button wire:click="savePayment" wire:loading.attr="disabled"
                        class="mt-4 w-full rounded-xl bg-emerald-600 py-4 text-base font-extrabold text-white shadow-md shadow-emerald-600/20 transition hover:bg-emerald-700 active:scale-[0.99]">
                    Record cash collection
                </button>
            </section>
        @endif
    @endif

    {{-- Bottom nav: quick back --}}
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('collector.dashboard') }}"
       class="block rounded-xl py-2 text-center text-sm font-semibold text-slate-500 hover:text-slate-800">
        ← Back
    </a>
</div>
