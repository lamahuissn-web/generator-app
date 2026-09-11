@assets
<script>
    /**
     * The two things a phone in the street needs from this screen that the server round
     * trip is too slow to give: the arithmetic updates as the collector types, and Enter
     * jumps to the next meter instead of dismissing the keyboard.
     *
     * The numbers here are the screen's own preview; the authoritative figures come back
     * from BillingService when the field is left (wire:model.blur) and again on save.
     * Registered once per page, so no listener can pile up across Livewire renders.
     */
    window.ReadingSheet = {
        money: function (n) {
            return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        cell: function (row, name) {
            return row.querySelector('[data-cell="' + name + '"]');
        },
        refresh: function (input) {
            var row = input.closest('[data-reading-row]');
            var dash = function (name) {
                var el = ReadingSheet.cell(row, name);
                if (el) { el.textContent = '—'; }
            };
            var raw = input.value.trim();

            if (raw === '' || isNaN(Number(raw))) {
                ['delta', 'usage', 'due'].forEach(dash);

                return;
            }

            var prev = parseFloat(row.dataset.prev || '0');
            var price = parseFloat(row.dataset.price || '0');
            var maintenance = parseFloat(row.dataset.maint || '0');
            var debt = parseFloat(row.dataset.debt || '0');

            var consumption = Math.round((Number(raw) - prev) * 100) / 100;
            var usage = Math.round(consumption * price * 100) / 100;
            var due = Math.round((usage + maintenance + debt) * 100) / 100;

            var delta = ReadingSheet.cell(row, 'delta');
            if (delta) {
                delta.textContent = consumption.toLocaleString('en-US', { maximumFractionDigits: 0 });
                // A reading below the last one is refused on save; say so in red now rather
                // than after the collector has walked away from the house.
                delta.classList.toggle('text-destructive', consumption < 0);
                delta.classList.toggle('text-mono', consumption >= 0);
            }

            var usageCell = ReadingSheet.cell(row, 'usage');
            if (usageCell) { usageCell.textContent = ReadingSheet.money(usage); }

            var dueCell = ReadingSheet.cell(row, 'due');
            if (dueCell) { dueCell.textContent = ReadingSheet.money(due); }
        },
        enter: function (input, event) {
            if (event.key !== 'Enter') { return; }

            event.preventDefault();

            var inputs = Array.prototype.slice.call(document.querySelectorAll('[data-reading-input]'));
            var next = inputs[inputs.indexOf(input) + 1];

            if (next) {
                next.focus();
            } else {
                // The last row: nothing to jump to, so leave the field instead of doing nothing.
                // The value is bound on blur, and a field that keeps focus while the collector
                // reaches for Save has never reached the server — the reading is then silently
                // missing from the save.
                input.blur();
            }
        },
    };
</script>
@endassets

<div>
    {{-- Heading: which route, which cycle, how much of it is done --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ $this->backUrl() }}" class="kt-btn kt-btn-icon kt-btn-outline shrink-0">
                <i class="ki-filled ki-arrow-left rtl:rotate-180"></i>
            </a>
            <div class="min-w-0">
                <h1 class="truncate text-xl font-medium leading-none text-mono">{{ __('portal.route_readings') }}</h1>
                <p class="mt-1 truncate text-sm text-muted-foreground">
                    {{ $route->name }} · {{ __('portal.readings_period', ['period' => period_label_i18n($period)]) }}
                </p>
            </div>
        </div>

        <span class="kt-badge kt-badge-lg kt-badge-outline shrink-0 {{ $progress['total'] > 0 && $progress['pending'] === 0 ? 'kt-badge-success' : '' }}">
            {{ __('portal.readings_progress', ['done' => $progress['done'], 'total' => $progress['total']]) }}
        </span>
    </div>

    @if ($flash)
        <div class="kt-alert kt-alert-light mb-4 {{ $flashType === 'error' ? 'kt-alert-destructive' : ($flashType === 'warning' ? 'kt-alert-warning' : 'kt-alert-success') }}">
            <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
            <div class="kt-alert-content">
                <div class="kt-alert-title">{{ $flash }}</div>
            </div>
            <div class="kt-alert-actions">
                <button type="button" wire:click="dismissFlash" class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost shrink-0">
                    <i class="ki-filled ki-cross"></i>
                </button>
            </div>
        </div>
    @endif

    @if ($failed !== [])
        <div class="mb-4 rounded-lg border border-destructive/40 bg-destructive/5 p-3.5">
            <p class="text-sm font-semibold text-destructive">{{ __('portal.bulk_failed_title') }}</p>
            <ul class="mt-1.5 grid gap-1">
                @foreach ($failed as $row)
                    <li class="text-sm">
                        <span class="font-semibold text-mono">{{ $row['name'] }}</span>
                        <span class="text-muted-foreground"> — </span>
                        <span>{{ $row['reason'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($progress['total'] === 0)
        <div class="kt-card">
            <div class="kt-card-content flex flex-col items-center gap-2 text-center">
                <span class="tile tile-muted size-12 text-xl"><i class="ki-filled ki-bill"></i></span>
                <span class="font-semibold text-mono">{{ __('portal.bulk_no_metered') }}</span>
                <a href="{{ $this->backUrl() }}" class="kt-btn kt-btn-lg mt-1 justify-center">
                    {{ __('portal.back') }}
                </a>
            </div>
        </div>
    @else
        @if ($progress['pending'] === 0)
            <div class="kt-alert kt-alert-light kt-alert-success mb-4">
                <div class="kt-alert-icon"><i class="ki-filled ki-check-circle text-base"></i></div>
                <div class="kt-alert-content">
                    <div class="kt-alert-title">{{ __('portal.bulk_all_done', ['period' => period_label_i18n($period)]) }}</div>
                </div>
            </div>
        @else
            <p class="mb-3 text-xs text-muted-foreground">{{ __('portal.bulk_help') }}</p>
        @endif

        <div class="grid gap-2.5">
            @foreach ($rows as $row)
                @php $preview = $row['preview']; @endphp
                <div class="kt-card {{ $row['done'] ? 'opacity-90' : 'border-primary/30' }}"
                     data-reading-row
                     data-prev="{{ $row['previous'] }}"
                     data-price="{{ $price }}"
                     data-maint="{{ $row['maintenance'] }}"
                     data-debt="{{ $row['debt'] }}"
                     wire:key="reading-row-{{ $row['id'] }}">
                    <div class="kt-card-content py-3">
                        {{-- Who, and where they stand this cycle --}}
                        <div class="flex items-center gap-2">
                            <span class="truncate font-semibold text-mono">{{ $row['name'] }}</span>
                            @if ($row['broken'])
                                <span class="kt-badge kt-badge-xs kt-badge-outline kt-badge-warning shrink-0">
                                    <i class="ki-filled ki-information"></i>
                                    {{ __('t.meter_broken_badge') }}
                                </span>
                            @endif
                            <span class="grow"></span>
                            @if ($row['done'])
                                <span class="kt-badge kt-badge-sm kt-badge-success shrink-0">
                                    <i class="ki-filled ki-check"></i>
                                    {{ __('portal.reading_done_chip') }}
                                </span>
                            @else
                                <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">{{ __('portal.reading_pending_chip') }}</span>
                            @endif
                        </div>

                        <p class="mt-1 truncate text-xs text-muted-foreground">
                            {{ collect([
                                $row['meter_no'] ? __('portal.meter').' '.ltr_isolate($row['meter_no']) : null,
                                $row['amperage'] ? ltr_isolate($row['amperage'].'A') : null,
                                __('portal.reading_prev_short').': '.number_format($row['previous'], 0),
                            ])->filter()->implode(' · ') }}
                        </p>

                        @if ($row['done'])
                            {{-- Already in: the cycle's bill, read-only. Anything typed here would
                                 be refused by the meter, so the row offers a way into the house
                                 instead of an input going nowhere. --}}
                            <div class="mt-2.5 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-sm">
                                <span>
                                    <span class="text-muted-foreground">{{ __('portal.reading_new_short') }}</span>
                                    <span class="amount-cell font-semibold text-mono">{{ number_format($row['recorded']['current'], 0) }}</span>
                                </span>
                                <span>
                                    <span class="text-muted-foreground">{{ __('portal.consumption') }}</span>
                                    <span class="amount-cell font-semibold text-mono">{{ number_format($row['recorded']['consumption'], 1) }} kWh</span>
                                </span>
                                <span class="grow"></span>
                                <span class="amount-cell font-semibold text-primary">{{ money($row['recorded']['total']) }}</span>
                            </div>

                            @if ($row['recorded']['estimated'])
                                <span class="kt-badge kt-badge-xs kt-badge-outline kt-badge-warning mt-2">
                                    {{ __('portal.reading_estimated_badge') }}
                                </span>
                            @endif
                        @elseif ($row['broken'])
                            {{-- A reported-dead meter has no value to type. The meter cannot answer,
                                 so an input here invites a number that cannot exist — and the case is
                                 the office's to close, not the collector's to fill in. The two ways
                                 out are the meter working again (undo, below) or the office deciding. --}}
                            <div class="mt-2.5 rounded-lg border border-yellow-400/40 bg-yellow-400/10 p-3">
                                <div class="flex items-center gap-1.5 text-xs font-semibold text-yellow-700 dark:text-yellow-600">
                                    <i class="ki-filled ki-information"></i>
                                    {{ __('t.meter_broken_badge') }}
                                </div>
                                <p class="mt-1 text-sm text-secondary-foreground">{{ __('portal.broken_waiting_office') }}</p>
                            </div>
                        @else
                            <input type="text" inputmode="numeric" enterkeyhint="next" autocomplete="off" dir="ltr"
                                   data-reading-input
                                   oninput="ReadingSheet.refresh(this)"
                                   onkeydown="ReadingSheet.enter(this, event)"
                                   wire:model.blur="values.{{ $row['id'] }}"
                                   placeholder="{{ $row['previous'] > 0 ? number_format($row['previous'], 0) : __('portal.reading_ph') }}"
                                   class="kt-input mt-2 w-full py-3 text-center text-2xl font-semibold tabular-nums">

                            {{-- The running bill for this row: instant arithmetic, confirmed by
                                 the server the moment the field is left. --}}
                            <div class="mt-2 grid grid-cols-3 gap-2 text-center text-2xs">
                                <div class="rounded-lg bg-muted p-2">
                                    <div class="text-muted-foreground">{{ __('portal.consumption') }}</div>
                                    <div class="mt-0.5 text-sm font-semibold text-mono">
                                        <span data-cell="delta">{{ $preview ? number_format($preview['consumption'], 0) : '—' }}</span>
                                        <span class="text-2xs text-muted-foreground">kWh</span>
                                    </div>
                                </div>
                                <div class="rounded-lg bg-muted p-2">
                                    <div class="text-muted-foreground">{{ __('portal.reading_preview_usage') }}</div>
                                    <div class="mt-0.5 text-sm font-semibold text-mono">
                                        <span data-cell="usage">{{ $preview ? money($preview['usage']) : '—' }}</span>
                                    </div>
                                </div>
                                <div class="rounded-lg bg-muted p-2">
                                    <div class="text-muted-foreground">{{ __('portal.due_now') }}</div>
                                    <div class="mt-0.5 text-sm font-semibold text-primary">
                                        <span data-cell="due">{{ $preview ? money($preview['due']) : '—' }}</span>
                                    </div>
                                </div>
                            </div>

                            @if ($preview && $preview['warning'])
                                {{-- The wording comes from ReadingQuality, the same source the
                                     office's board reads: one phrasing for what "a zero" and
                                     "a jump" mean in this app. --}}
                                <div class="mt-2">
                                    <span @class([
                                        'kt-badge kt-badge-sm',
                                        'kt-badge-destructive' => $preview['warning'] === 'lower',
                                        'kt-badge-outline kt-badge-warning' => $preview['warning'] !== 'lower',
                                    ])>
                                        @if ($preview['warning'] === 'lower')
                                            <i class="ki-filled ki-information"></i>
                                            {{ __('portal.warn_lower') }}
                                        @else
                                            {{ \App\Services\ReadingQuality::label($preview['warning'], $preview['average']) }}
                                        @endif
                                    </span>
                                </div>
                            @endif
                        @endif

                        @isset ($failed[$row['id']])
                            <p class="mt-2 text-sm font-medium text-destructive">{{ $failed[$row['id']]['reason'] }}</p>
                        @endisset

                        <div class="mt-2.5 flex items-center gap-2">
                            @if ($row['done'])
                                {{-- Nothing to read: the row's job is a payment or a correction. --}}
                            @elseif ($row['broken'])
                                <button type="button" wire:click="clearBroken({{ $row['id'] }})"
                                        class="kt-btn kt-btn-sm kt-btn-ghost">
                                    {{ __('portal.broken_undo') }}
                                </button>
                            @elseif ($preview === null)
                                {{-- Only while the field is empty: a typed value is a reading, and
                                     the two statements contradict each other. --}}
                                <button type="button" wire:click="reportBroken({{ $row['id'] }})"
                                        class="kt-btn kt-btn-sm kt-btn-outline kt-btn-destructive">
                                    <i class="ki-filled ki-information"></i>
                                    {{ __('portal.broken_btn') }}
                                </button>
                            @endif

                            <span class="grow"></span>

                            <a href="{{ $this->subscriberUrl($row['id']) }}"
                               class="kt-btn kt-btn-sm kt-btn-ghost">
                                {{ __('portal.overview') }}
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Footer counts: who this screen will never offer a reading for, so a subscriber
             missing from the list is an answer and not a question. --}}
        @if ($flat > 0 || $inactive > 0)
            <p class="mt-3 px-1 text-2xs text-muted-foreground">
                {{ collect([
                    $flat > 0 ? plural('portal.bulk_flat_note', $flat) : null,
                    $inactive > 0 ? plural('portal.bulk_inactive_note', $inactive) : null,
                ])->filter()->implode(' · ') }}
            </p>
        @endif

        @if ($progress['pending'] > 0)
            {{-- Sticky: the save is the end of the walk, and it must never be a scroll away. --}}
            <div class="sticky bottom-0 z-10 mt-4 border-t border-border bg-background/95 py-3 backdrop-blur">
                <div class="flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-xs font-medium text-muted-foreground">
                            {{ plural('portal.typed_readings', $typed) }}
                        </div>
                        <div class="truncate text-sm font-semibold text-mono">
                            {{ money($typedTotal) }}
                            <span class="text-2xs font-medium text-muted-foreground">{{ __('portal.reading_sum_total') }}</span>
                        </div>
                    </div>

                    {{-- Never disabled on the server's own count.
                         The count is computed per render and the values arrive on blur
                         (wire:model.blur), so while the collector is typing the server still sees
                         zero — and a disabled button swallows the click that would have carried the
                         new values. Measured in use: type three meters, press Save, nothing. The
                         guard against an empty save is in save() itself, which says "nothing typed";
                         the only disable left here watches save's own request. --}}
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save"
                            class="kt-btn kt-btn-lg shrink-0">
                        <i class="ki-filled ki-check"></i>
                        {{ __('portal.save_readings') }}
                    </button>
                </div>
            </div>
        @endif
    @endif
</div>
