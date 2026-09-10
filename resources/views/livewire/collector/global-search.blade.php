<div class="mb-5">
    <div class="kt-input kt-input-lg">
        <i class="ki-filled ki-magnifier"></i>
        <input type="search" wire:model.live.debounce.250ms="q" placeholder="{{ __('portal.search_ph') }}"
               autocomplete="off">
        @if (trim($q) !== '')
            <button type="button" wire:click="$set('q', '')" class="kt-btn kt-btn-icon kt-btn-ghost kt-btn-sm -me-1.5"
                    aria-label="{{ __('portal.search_clear') }}">
                <i class="ki-filled ki-cross"></i>
            </button>
        @endif
    </div>

    @php $results = $this->results; @endphp

    @if (count($results))
        <div class="kt-card mt-2.5 overflow-hidden">
            @foreach ($results as $r)
                <a href="{{ route('collector.subscriber.show', $r['id']) }}"
                   class="flex items-center gap-3 border-b border-border px-4 py-3 last:border-b-0 transition hover:bg-muted/60">
                    <span class="tile {{ $r['type'] === 'metered' ? 'tile-info' : 'tile-violet' }} size-9 text-2xs font-semibold">
                        {{ $r['type'] === 'metered' ? 'MTR' : 'FLT' }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-sm font-semibold text-mono">{{ $r['name'] }}</span>
                            @if ($r['status'] === 'inactive')
                                <span class="kt-badge kt-badge-xs kt-badge-outline">{{ __('portal.inactive') }}</span>
                            @endif
                        </span>
                        <span class="block truncate text-xs text-muted-foreground">
                            {{ collect([$r['meter_no'] ? __('portal.meter').' '.ltr_isolate($r['meter_no']) : null, $r['route']])->filter()->implode(' · ') ?: __('portal.no_route') }}
                        </span>
                    </span>
                    <span class="shrink-0 text-right">
                        @if ($r['owed'] > 0)
                            <span class="block text-sm font-semibold tabular-nums text-destructive">{{ money($r['owed']) }}</span>
                            <span class="block text-2xs font-medium uppercase tracking-wide text-muted-foreground">{{ __('portal.due') }}</span>
                        @else
                            <span class="block text-sm font-semibold tabular-nums text-green-600">0</span>
                            <span class="block text-2xs font-medium uppercase tracking-wide text-green-600">{{ __('portal.settled') }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
    @elseif (mb_strlen(trim($q)) >= 2)
        <div class="kt-card mt-2.5 border-dashed">
            <div class="kt-card-content text-center text-sm text-muted-foreground">
                {{ __('portal.search_empty', ['q' => trim($q)]) }}
            </div>
        </div>
    @endif
</div>
