<x-filament-widgets::widget>
    <div class="grid gap-4 xl:grid-cols-2">
        {{-- Latest payments --}}
        <x-filament::section heading="Latest collections" icon="heroicon-o-banknotes">
            @php $payments = $this->payments(); @endphp
            @if ($payments->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No payments recorded yet.</p>
            @else
                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($payments as $p)
                        <div class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $p->subscriber?->name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $p->created_at->format('M j, H:i') }}
                                    @if ($p->collector) · {{ $p->collector->name }} @endif
                                </p>
                            </div>
                            <span class="shrink-0 text-sm font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ money($p->amount) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Top debtors --}}
        <x-filament::section heading="Top outstanding balances" icon="heroicon-o-exclamation-triangle">
            @php $debtors = $this->topDebtors(); @endphp
            @if ($debtors->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Everyone is settled 🎉</p>
            @else
                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($debtors as $d)
                        <div class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $d->subscriber_name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $d->route_name ?? 'No route' }}
                                    · {{ $d->bills_open }} open {{ $d->bills_open == 1 ? 'bill' : 'bills' }}
                                </p>
                            </div>
                            <span class="shrink-0 text-sm font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ money($d->owed) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
