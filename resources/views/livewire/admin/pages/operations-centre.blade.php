<div class="grid gap-4">
    <x-admin.filters :fields="$this->filterSchema()" :values="$filters" id-prefix="ops" :reset="false" />

    @php
        $m = $board['money'];
        $e = $board['energy'];
        $f = $board['fuel'];
        $c = $board['cost'];
        $hasProduction = $e['produced'] !== null;
        $lossMissing = $e['loss_reason'] !== null;
    @endphp

    {{-- Six plain cards — the office's own words, in the order they are reasoned about. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-admin.stat :label="__('ops.income')" :value="money($m['collected'])" icon="ki-wallet"
                      :hint="__('ops.income_hint')" />
        <x-admin.stat :label="__('ops.earned')" :value="money($m['earned'])" icon="ki-chart-line-up"
                      tone="success" :hint="__('ops.earned_hint')" />
        <x-admin.stat :label="__('ops.held')" :value="money($m['held'])" icon="ki-safe"
                      :hint="__('ops.held_hint')" />
        <x-admin.stat :label="__('ops.overdue')" :value="money($overdue)" icon="ki-information-2"
                      tone="{{ $overdue > 0 ? 'warning' : 'success' }}" :hint="__('ops.overdue_hint')" />
        <x-admin.stat :label="__('ops.expenses')" :value="money($m['expenses'])" icon="ki-chart-line-down"
                      tone="danger" :hint="__('ops.expenses_hint')" />
        <x-admin.stat :label="__('ops.net')" :value="money($m['net'])" icon="ki-dollar"
                      :tone="$m['net'] >= 0 ? 'success' : 'danger'"
                      :hint="__('ops.net_sub')" />
    </div>

    {{-- Feature 004 disclosure lines — beside the net, never inside it. --}}
    <x-admin.card>
        <div class="grid gap-3 text-sm sm:grid-cols-3">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-muted-foreground">{{ __('ops.discounts') }}</span>
                <span class="amount-cell font-medium text-mono">{{ money($m['discounts'] ?? 0) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-muted-foreground">{{ __('ops.subsidies') }}</span>
                <span class="amount-cell font-medium text-mono">{{ money($m['subsidies'] ?? 0) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-muted-foreground">{{ __('ops.exempt_value') }}</span>
                <span class="amount-cell font-medium text-mono">{{ money($m['exempt_value'] ?? 0) }}</span>
            </div>
        </div>
    </x-admin.card>

    {{-- The engineering toggle — one button, everything advanced behind it. --}}
    <div class="flex justify-center">
        <button type="button" wire:click="toggleMore" class="kt-btn kt-btn-sm kt-btn-outline">
            <i class="ki-filled {{ $more ? 'ki-up' : 'ki-down' }}"></i>
            {{ $more ? __('ops.less') : __('ops.more') }}
        </button>
    </div>

    @if ($more)
        <div class="grid gap-4">
            {{-- Fuel --}}
            <x-admin.card :title="__('ops.fuel_title')">
                @if ($f['litres'] > 0 || $f['money'] > 0)
                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-muted-foreground">{{ __('ops.fuel_litres') }}</span>
                            <span class="font-medium" dir="ltr">{{ number_format($f['litres'], 0) }} {{ __('t.liters') }}</span>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-muted-foreground">{{ __('ops.fuel_money') }}</span>
                            <span class="amount-cell font-medium text-mono">{{ money($f['money']) }}</span>
                        </div>
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-muted-foreground">{{ __('ops.fuel_avg') }}</span>
                            <span class="amount-cell font-medium text-mono">
                                @if ($f['average_price'] !== null)
                                    {{ '$'.number_format($f['average_price'], 3) }}
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                            </span>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-muted-foreground">{{ __('ops.no_production') }}</p>
                @endif
            </x-admin.card>

            {{-- Production --}}
            <x-admin.card :title="__('ops.produced')">
                @if ($hasProduction)
                    <div class="flex flex-wrap items-baseline gap-3">
                        <span class="text-2xl font-semibold" dir="ltr">{{ number_format($e['produced'], 1) }} {{ __('t.kwh') }}</span>
                        <span class="text-sm text-muted-foreground">{{ __('ops.basis_label', ['basis' => $e['production_basis_ar']]) }}</span>
                    </div>
                    @if ($board['fuel']['litres'] > 0 && $e['produced_from_litres'] > 0)
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ number_format($e['produced_measured'], 1) }} {{ __('t.kwh') }} {{ __('ops.basis_measured') }} +
                            {{ number_format($e['produced_from_litres'], 1) }} {{ __('t.kwh') }} {{ __('ops.basis_estimated') }}
                        </p>
                    @endif
                    @php $report = \App\Reports\OperationsReport::forMonth($period); @endphp
                    @if ($report->rateIsDefault())
                        <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">{{ __('ops.default_rate_note') }}</p>
                    @endif
                @else
                    <p class="text-sm text-muted-foreground">{{ __('ops.no_production') }}</p>
                @endif
            </x-admin.card>

            {{-- Consumption --}}
            <x-admin.card :title="__('ops.consumed')">
                <div class="flex flex-wrap items-baseline gap-3">
                    <span class="text-2xl font-semibold" dir="ltr">{{ number_format($e['consumed'], 1) }} {{ __('t.kwh') }}</span>
                    <span class="text-sm text-muted-foreground">
                        {{ number_format($e['consumed_metered'], 1) }} {{ __('t.kwh') }}
                        @if ($e['flat_houses'] > 0)
                            + {{ $e['consumed_flat'] !== null ? number_format($e['consumed_flat'], 1) : '—' }} {{ __('t.kwh') }} ({{ $e['flat_houses'] }} {{ __('t.flat') }})
                        @endif
                    </span>
                </div>
                @if ($e['flat_houses'] > 0 && $e['consumed_flat'] === null)
                    {{-- The flat draw needs one metered reading to average; without it the part is unknown, not
                         zero, and saying so here is what keeps the consumption figure honest. --}}
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ __('ops.flat_unknown') }}</p>
                @endif
                @if ($e['unread_meters'] > 0)
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ __('ops.unread_note', ['count' => $e['unread_meters']]) }}</p>
                @endif
                @if ($e['estimated_readings'] > 0)
                    <p class="text-xs text-muted-foreground">{{ __('ops.estimated_share', ['count' => $e['estimated_readings']]) }}</p>
                @endif
            </x-admin.card>

            {{-- Loss --}}
            <x-admin.card :title="__('ops.loss')">
                @if ($lossMissing)
                    {{-- The RIGHT reason: without production it is a production question, without readings it is a
                         readings question — and the two are not interchangeable. --}}
                    <p class="text-sm text-muted-foreground">
                        {{ $e['loss_reason'] === 'no_readings' ? __('ops.no_readings') : __('ops.no_production') }}
                    </p>
                @else
                    <div class="flex flex-wrap items-baseline gap-3">
                        <span class="text-2xl font-semibold" dir="ltr">{{ number_format($e['loss_kwh'], 1) }} {{ __('t.kwh') }}</span>
                        <span @class(['text-sm font-medium', 'text-amber-700 dark:text-amber-400' => ($e['loss_verdict'] ?? null) === 'high', 'text-green-700 dark:text-green-500' => ($e['loss_verdict'] ?? null) === 'normal']) dir="ltr">{{ number_format($e['loss_percent'], 1) }}%</span>
                        @if ($e['loss_verdict'])
                            {{-- The verdict is a key, not a word: an English slug inside an Arabic screen is not a
                                 verdict the office can read. --}}
                            <span class="kt-badge kt-badge-sm {{ $e['loss_verdict'] === 'high' ? 'kt-badge-destructive' : ($e['loss_verdict'] === 'low' ? 'kt-badge-info' : 'kt-badge-success') }}">{{ __('ops.loss_verdict_'.$e['loss_verdict']) }}</span>
                        @endif
                    </div>
                    @if ($e['loss_note'])
                        <p class="mt-1 text-xs text-destructive">{{ $e['loss_note'] }}</p>
                    @endif
                    @if ($e['unread_meters'] > 0)
                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ __('ops.unread_note', ['count' => $e['unread_meters']]) }}</p>
                    @endif
                    @if ($e['loss_estimated_share'] !== null)
                        {{-- How much of the consumption this loss was measured against is an estimate — the loss
                             inherits that uncertainty and has to say so. --}}
                        <p class="mt-1 text-xs text-muted-foreground">{{ __('ops.loss_estimated', ['percent' => number_format($e['loss_estimated_share'], 1)]) }}</p>
                    @endif
                @endif
            </x-admin.card>

            {{-- Cost / profit / generosity --}}
            <div class="grid gap-4 sm:grid-cols-3">
                <x-admin.card :title="__('ops.cost_per_kwh')">
                    @if ($c['per_kwh'] !== null)
                        <span class="amount-cell text-xl font-semibold text-mono">{{ '$'.number_format($c['per_kwh'], 3) }}</span>
                    @else
                        <p class="text-sm text-muted-foreground">{{ __('ops.no_cost') }}</p>
                    @endif
                </x-admin.card>
                <x-admin.card :title="__('ops.profit_per_kwh')">
                    @if ($c['profit_per_kwh'] !== null)
                        <span class="amount-cell text-xl font-semibold text-mono">{{ '$'.number_format($c['profit_per_kwh'], 3) }}</span>
                    @else
                        <p class="text-sm text-muted-foreground">{{ __('ops.no_profit') }}</p>
                    @endif
                </x-admin.card>
                <x-admin.card :title="__('ops.generosity')" :description="__('ops.generosity_hint')">
                    @if (($c['generosity']['kwh'] ?? 0) > 0)
                        <div class="grid gap-1 text-sm">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-muted-foreground" dir="ltr">{{ number_format($c['generosity']['kwh'], 1) }} {{ __('t.kwh') }}</span>
                                <span class="amount-cell font-medium text-mono">
                                    {{ $c['generosity']['cost'] !== null ? money($c['generosity']['cost']) : '—' }}
                                </span>
                            </div>
                        </div>
                    @elseif ($c['generosity']['kwh'] === null)
                        {{-- An exempt house exists but its kWh cannot be worked out: the card names WHAT is
                             missing — a reading nobody has taken yet, or no average to anchor a flat draw —
                             instead of spending zero. --}}
                        <p class="text-sm text-muted-foreground">
                            {{ ($c['generosity']['reason'] ?? null) === 'unread' ? __('ops.generosity_unread') : __('ops.generosity_unknown') }}
                        </p>
                    @else
                        <p class="text-sm text-muted-foreground">{{ __('ops.no_generosity') }}</p>
                    @endif
                </x-admin.card>
            </div>

            {{-- Per-route energy — a card, not a separate report. --}}
            <x-admin.card :title="__('ops.per_route')" :description="__('ops.per_route_hint')">
                @if ($perRoute->isEmpty())
                    <p class="text-sm text-muted-foreground">{{ __('t.no_results') }}</p>
                @else
                    <div class="grid gap-2">
                        @foreach ($perRoute as $row)
                            <div class="flex items-baseline justify-between gap-3 border-b border-border pb-2 last:border-b-0 last:pb-0">
                                <span class="text-sm text-mono">{{ $row['route'] }}</span>
                                @if ($row['known'])
                                    <span class="text-sm font-medium" dir="ltr">{{ number_format($row['consumed'], 1) }} {{ __('t.kwh') }}</span>
                                @else
                                    {{-- A flat house with no metered average to anchor its draw: the figure is
                                         unknown, and a zero here would read as "this area consumes nothing". --}}
                                    <span class="text-sm text-muted-foreground">{{ __('ops.flat_unknown_short') }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-admin.card>
        </div>
    @endif
</div>
