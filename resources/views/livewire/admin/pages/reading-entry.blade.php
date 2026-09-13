<div class="grid gap-4">
    {{-- Which cycle: the office also enters a slip that arrived late. --}}
    <div class="kt-card max-w-md">
        <div class="kt-card-content grid gap-3 py-4">
            <div>
                <label class="kt-form-label" for="re-period">{{ __('t.period') }}</label>
                <div x-on:month-picked="$wire.set('period', $event.detail.month)" class="contents">
                    <livewire:admin.month-picker :value="$period" :key="'entry-month'" />
                </div>
            </div>
            <p class="text-xs text-muted-foreground">{{ __('rdge.period_hint') }}</p>
        </div>
    </div>

    @if ($route === null)
        {{-- No street chosen yet: the list of streets IS the screen, ordered by what is still
             unread, so opening this page answers "where do I start". --}}
        <div class="flex items-baseline justify-between px-1">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">{{ __('rdge.pick_route') }}</h2>
            <span class="text-xs text-muted-foreground">{{ plural('portal.areas_count', $routes->count()) }}</span>
        </div>

        @if ($routes->isEmpty())
            <div class="kt-card">
                <div class="kt-card-content text-center text-sm text-muted-foreground">{{ __('portal.no_routes') }}</div>
            </div>
        @endif

        <div class="grid gap-2.5">
            @foreach ($routes as $area)
                <button type="button" wire:click="selectRoute({{ $area['id'] }})"
                        wire:key="re-route-{{ $area['id'] }}"
                        class="kt-card text-start transition hover:border-primary/40">
                    <div class="kt-card-content flex items-center gap-3">
                        <span class="tile {{ $area['pending'] > 0 ? 'tile-warning' : 'tile-success' }} size-10 text-lg">
                            <i class="ki-filled ki-bill"></i>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-mono">{{ $area['name'] }}</span>
                            <span class="block text-xs text-muted-foreground">
                                {{ __('rdgb.of', ['done' => number_format($area['recorded']), 'total' => number_format($area['total'])]) }}
                            </span>
                        </span>
                        @if ($area['pending'] > 0)
                            <span class="kt-badge kt-badge-sm kt-badge-destructive shrink-0">
                                {{ number_format($area['pending']) }}
                            </span>
                        @else
                            <span class="kt-badge kt-badge-sm kt-badge-success shrink-0">
                                <i class="ki-filled ki-check"></i>
                                {{ __('gen.coverage_ok') }}
                            </span>
                        @endif
                    </div>
                </button>
            @endforeach
        </div>
    @else
        {{-- The street is chosen: the sheet itself, with the same rows, rules and one-save-at-the-end
             the field uses. Keyed by area and cycle so switching either starts a clean sheet instead
             of carrying half-typed values into another month. --}}
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="clearRoute" class="kt-btn kt-btn-sm kt-btn-outline">
                <i class="ki-filled ki-arrow-right rtl:rotate-180"></i>
                {{ __('rdge.change_route') }}
            </button>
        </div>

        <livewire:route-reading-sheet
            :route="$route"
            :period="$period"
            :key="'sheet-'.$route->id.'-'.$period" />
    @endif
</div>
