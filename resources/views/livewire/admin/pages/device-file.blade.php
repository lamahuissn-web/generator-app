<div class="grid gap-4">
    {{-- The device's data --}}
    <div class="kt-card">
        <div class="kt-card-content">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-mono">{{ $equipment->name }}</h2>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $equipment->kindLabel() }}</span>
                        <span class="kt-badge kt-badge-sm {{ $equipment->status === 'working' ? 'kt-badge-success' : ($equipment->status === 'service' ? 'kt-badge-warning' : 'kt-badge-outline') }}">{{ $equipment->statusLabel() }}</span>
                        @if ($equipment->capacity)
                            <span>{{ __('ops.device_capacity', ['capacity' => $equipment->capacity]) }}</span>
                        @endif
                        @if ($equipment->serial)
                            <span dir="ltr">#{{ $equipment->serial }}</span>
                        @endif
                    </div>
                    <div class="mt-2 flex flex-wrap gap-3 text-xs text-muted-foreground">
                        @if ($equipment->installed_on)
                            <span>{{ __('ops.device_installed', ['date' => $equipment->installed_on->format('Y-m-d')]) }}</span>
                        @endif
                        @php $hours = $equipment->currentEngineHours(); @endphp
                        @if ($hours !== null)
                            <span>{{ __('ops.device_hours', ['hours' => number_format($hours, 0)]) }}</span>
                        @endif
                    </div>
                    @if ($equipment->notes)
                        <p class="mt-2 text-sm text-muted-foreground">{{ $equipment->notes }}</p>
                    @endif
                </div>
                <a href="{{ route('admin.equipment.edit', $equipment) }}" class="kt-btn kt-btn-sm kt-btn-outline">
                    <i class="ki-filled ki-pencil"></i>
                    {{ __('t.edit') }}
                </a>
            </div>
        </div>
    </div>

    {{-- Service + anomaly --}}
    <div class="grid gap-4 sm:grid-cols-2">
        <x-admin.card :title="__('ops.service_next')">
            @if ($nextService['wording'])
                <div class="flex flex-wrap items-baseline gap-2">
                    <span class="text-lg font-semibold {{ ($nextService['hours_left'] ?? 0) < 0 ? 'text-destructive' : 'text-mono' }}">{{ $nextService['wording'] }}</span>
                    @if ($nextService['date'])
                        <span class="text-sm text-muted-foreground" dir="ltr">{{ $nextService['date'] }}</span>
                    @endif
                </div>
                @if ($nextService['threshold'])
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ number_format($nextService['current'] ?? 0, 0) }} / {{ number_format($nextService['threshold'], 0) }} {{ __('ops.hours_unit') }}
                    </p>
                @endif
            @else
                {{-- The reason must be the RIGHT reason: this card is about engine hours, so it asks for
                     hours — not for the production figure the board's energy cards ask for. --}}
                <p class="text-sm text-muted-foreground">{{ __('ops.service_no_hours') }}</p>
            @endif
            @if ($nextService['beyond_bands'] ?? false)
                <p class="mt-1 text-xs text-muted-foreground">{{ __('eq.beyond_bands') }}</p>
            @endif
        </x-admin.card>

        <x-admin.card :title="__('ops.anomaly')">
            @if ($anomaly)
                <p class="text-sm {{ $anomaly['direction'] === 'up' ? 'text-amber-700 dark:text-amber-400' : 'text-blue-700 dark:text-blue-400' }}">
                    {{ $anomaly['direction'] === 'up'
                        ? __('ops.anomaly_up', ['current' => $anomaly['litres_per_hour'], 'history' => $anomaly['history'], 'deviation' => $anomaly['deviation']])
                        : __('ops.anomaly_down', ['current' => $anomaly['litres_per_hour'], 'history' => $anomaly['history'], 'deviation' => abs($anomaly['deviation'])])
                    }}
                </p>
            @else
                <p class="text-sm text-muted-foreground">{{ __('eq.anomaly_ok') }}</p>
            @endif
        </x-admin.card>
    </div>

    {{-- Two buttons, each a modal --}}
    <div class="flex flex-wrap gap-2">
        <livewire:admin.action-form :action="\App\Admin\Actions\RecordRefuelling::class" :subject-id="$equipment->id" :return-to="$returnTo" :key="'refuel-'.$equipment->id" />
        <livewire:admin.action-form :action="\App\Admin\Actions\RecordService::class" :subject-id="$equipment->id" :return-to="$returnTo" :key="'service-'.$equipment->id" />
    </div>

    {{-- Timeline --}}
    <x-admin.card :title="__('ops.timeline_title')">
        @if ($timeline->isEmpty())
            <p class="text-sm text-muted-foreground">{{ __('ops.timeline_empty') }}</p>
        @else
            <div class="grid gap-3">
                @foreach ($timeline as $entry)
                    <div class="flex gap-3 border-b border-border pb-3 last:border-b-0 last:pb-0">
                        <span class="mt-1 size-2 shrink-0 rounded-full {{ $entry['kind'] === 'refuel' ? 'bg-amber-500' : 'bg-primary' }}"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline gap-2">
                                <span class="text-sm font-medium text-mono">{{ $entry['label'] }}</span>
                                <span class="text-xs text-muted-foreground" dir="ltr">{{ $entry['date'] }}</span>
                            </div>
                            @if ($entry['detail'])
                                <p class="mt-0.5 text-xs text-muted-foreground">{{ $entry['detail'] }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-admin.card>
</div>
