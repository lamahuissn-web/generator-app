<x-filament-widgets::widget>
    <div class="gx-two-col">
        {{-- Latest payments --}}
        <x-filament::section heading="{{ __('w.latest_collections') }}" icon="heroicon-o-banknotes">
            @php $payments = $this->payments(); @endphp
            @if ($payments->isEmpty())
                <p class="gx-empty">{{ __('w.no_payments') }}</p>
            @else
                <div class="gx-feed">
                    @foreach ($payments as $p)
                        <div class="gx-feed-item">
                            <div style="min-width:0">
                                <div class="gx-feed-name">{{ $p->subscriber?->name }}</div>
                                <div class="gx-feed-meta">
                                    {{ $p->created_at->format('M j, H:i') }}
                                    @if ($p->collector) · {{ $p->collector->name }} @endif
                                </div>
                            </div>
                            <span class="gx-feed-amount gx-feed-pos">{{ money($p->amount) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Top debtors --}}
        <x-filament::section heading="{{ __('w.top_balances') }}" icon="heroicon-o-exclamation-triangle">
            @php $debtors = $this->topDebtors(); @endphp
            @if ($debtors->isEmpty())
                <p class="gx-empty">{{ __('w.all_settled') }}</p>
            @else
                <div class="gx-feed">
                    @foreach ($debtors as $d)
                        <div class="gx-feed-item">
                            <div style="min-width:0">
                                <div class="gx-feed-name">{{ $d->subscriber_name }}</div>
                                <div class="gx-feed-meta">
                                    {{ $d->route_name ?? 'No route' }}
                                    · {{ $d->bills_open }} open {{ $d->bills_open == 1 ? 'bill' : 'bills' }}
                                </div>
                            </div>
                            <span class="gx-feed-amount gx-feed-neg">{{ money($d->owed) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
