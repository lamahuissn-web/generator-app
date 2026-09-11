@php
    $percentage = static fn (float $part, float $whole): float => $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
@endphp

<div class="grid gap-4">
    {{-- A single month, so there is nothing to reset back to. --}}
    <x-admin.filters :fields="$this->filterSchema()" :values="$filters" id-prefix="pl" :reset="false" />

    {{-- The four figures the month comes down to, in the order they are reasoned about:
         what was billed, what came in, what that rate is, what was spent. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-admin.stat :label="__('pl.invoiced')" :value="money($report['invoiced'])" icon="ki-receipt-square"
                      :hint="$report['label']" />
        <x-admin.stat :label="__('pl.income')" :value="money($report['collected'])" icon="ki-wallet" tone="success" />
        <x-admin.stat :label="__('pl.rate')" :value="ltr_isolate(number_format($report['rate'], 1).'%')" icon="ki-percentage"
                      :tone="$report['rate'] >= 80 ? 'success' : ($report['rate'] >= 50 ? 'warning' : 'danger')" />
        <x-admin.stat :label="__('pl.expenses_total')" :value="money($report['expenses'])" icon="ki-chart-line-down"
                      tone="danger" />
    </div>

    {{-- Net is called out on its own: it is the number that decides whether the month worked,
         and it is the only figure here that can legitimately be negative. --}}
    <div class="kt-card">
        <div class="kt-card-content flex flex-wrap items-center justify-between gap-3">
            <span class="text-sm font-medium text-mono">{{ __('pl.net') }}</span>
            <span @class([
                'amount-cell text-3xl font-semibold',
                'text-green-700 dark:text-green-500' => $report['net'] >= 0,
                'text-destructive' => $report['net'] < 0,
            ])>{{ money($report['net']) }}</span>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-admin.card :title="__('pl.by_category')">
            @if ($report['byCategory']->isEmpty())
                <p class="text-sm text-muted-foreground">{{ __('t.no_results') }}</p>
            @else
                <div class="grid gap-3">
                    @foreach ($report['byCategory'] as $row)
                        @php $share = $percentage($row['total'], $report['expenses']); @endphp
                        <div class="grid gap-1.5">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-sm text-mono">{{ $row['label'] }}</span>
                                <span class="flex items-baseline gap-2">
                                    <span class="text-xs text-muted-foreground">{{ $row['count'] }}</span>
                                    <span class="amount-cell text-sm font-semibold text-mono">{{ money($row['total']) }}</span>
                                </span>
                            </div>
                            {{-- The bar is what makes the biggest cost visible at a glance; the
                                 width is a percentage of the month's own expense total. The track
                                 is zinc-200, not bg-muted: muted is zinc-100, which on a white
                                 card is invisible, and the fill then reads as a stray line. --}}
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800"
                                 role="progressbar" aria-valuenow="{{ $share }}" aria-valuemin="0" aria-valuemax="100"
                                 aria-label="{{ $row['label'] }}">
                                <div class="h-full rounded-full bg-primary" style="width: {{ $share }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.card>

        <x-admin.card :title="__('pl.recent')">
            @if ($report['recentExpenses']->isEmpty())
                <p class="text-sm text-muted-foreground">{{ __('t.no_results') }}</p>
            @else
                <div class="grid gap-3">
                    @foreach ($report['recentExpenses'] as $expense)
                        <div class="flex items-baseline justify-between gap-3 border-b border-border pb-2 last:border-b-0 last:pb-0">
                            <div class="min-w-0">
                                <div class="truncate text-sm text-mono">
                                    {{ $expense->description ?: \App\Models\Expense::label($expense->category) }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ \App\Models\Expense::label($expense->category) }} ·
                                    <span dir="ltr">{{ $expense->expense_date?->format('Y-m-d') }}</span>
                                </div>
                            </div>
                            <span class="amount-cell text-sm font-medium text-mono">{{ money($expense->amount) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.card>
    </div>
</div>
