<div class="mb-5">
    <div class="relative">
        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400 rtl:left-auto rtl:right-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
        <input type="search" wire:model.live.debounce.250ms="q" placeholder="{{ __('portal.search_ph') }}"
               autocomplete="off"
               class="w-full rounded-2xl border-0 bg-white py-3.5 pl-12 pr-4 text-base shadow-sm ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:ring-2 focus:ring-amber-400 focus:outline-none rtl:pl-4 rtl:pr-12">
    </div>

    @php $results = $this->results; @endphp

    @if (count($results))
        <div class="mt-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            @foreach ($results as $r)
                <a href="{{ route('collector.subscriber.show', $r['id']) }}"
                   class="flex items-center gap-3 border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-amber-50/60">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-[11px] font-bold {{ $r['type'] === 'metered' ? 'bg-sky-100 text-sky-700' : 'bg-violet-100 text-violet-700' }}">
                        {{ $r['type'] === 'metered' ? 'MTR' : 'FLT' }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-sm font-bold text-slate-900">{{ $r['name'] }}</span>
                            @if ($r['status'] === 'inactive')
                                <span class="rounded-full bg-slate-200 px-1.5 py-px text-[9px] font-bold uppercase text-slate-500">{{ __('portal.inactive') }}</span>
                            @endif
                        </span>
                        <span class="block truncate text-xs text-slate-500">
                            {{ collect([$r['meter_no'] ? __('portal.meter').' '.$r['meter_no'] : null, $r['route']])->filter()->implode(' · ') ?: __('portal.no_route') }}
                        </span>
                    </span>
                    @if ($r['owed'] > 0)
                        <span class="shrink-0 text-sm font-bold tabular-nums text-rose-600">{{ money($r['owed']) }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    @elseif (mb_strlen(trim($q)) >= 2)
        <div class="mt-2 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-4 py-6 text-center text-sm text-slate-400">
            {{ __('portal.search_empty', ['q' => trim($q)]) }}
        </div>
    @endif
</div>
