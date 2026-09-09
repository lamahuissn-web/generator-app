@extends('layouts.collector')

@section('title', $route->name)

@section('content')
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('collector.dashboard') }}" class="grid h-9 w-9 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:border-amber-300 hover:text-amber-600">
            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ $route->name }}</h1>
            <p class="text-xs text-slate-500">{{ $subscribers->count() }} subscribers @if ($route->notes) · {{ $route->notes }} @endif</p>
        </div>
    </div>

    @if ($subscribers->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-400">
            No subscribers on this route yet.
        </div>
    @endif

    <div class="space-y-2.5">
        @foreach ($subscribers as $subscriber)
            @php
                $owed = (float) ($owed[$subscriber->id] ?? 0);
                $inactive = $subscriber->status === 'inactive';
            @endphp
            <a href="{{ route('collector.subscriber.show', $subscriber) }}"
               class="group flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-amber-300 hover:shadow-md {{ $inactive ? 'opacity-60' : '' }}">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl text-sm font-bold {{ $subscriber->type === 'metered' ? 'bg-sky-100 text-sky-700' : 'bg-violet-100 text-violet-700' }}">
                    {{ $subscriber->type === 'metered' ? 'MTR' : 'FLT' }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center gap-2">
                        <span class="truncate font-bold text-slate-900">{{ $subscriber->name }}</span>
                        @if ($inactive)
                            <span class="shrink-0 rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600">Inactive</span>
                        @endif
                    </span>
                    <span class="block truncate text-xs text-slate-500">
                        {{ collect([$subscriber->meter_no ? 'Meter '.$subscriber->meter_no : null, $subscriber->amperage ? $subscriber->amperage.'A' : null, $subscriber->address])->filter()->implode(' · ') }}
                    </span>
                </span>
                <span class="shrink-0 text-right">
                    @if ($owed > 0)
                        <span class="block font-bold tabular-nums text-rose-600">{{ money($owed) }}</span>
                        <span class="block text-[10px] font-semibold uppercase tracking-wide text-rose-400">due</span>
                    @else
                        <span class="block font-bold tabular-nums text-emerald-600">0</span>
                        <span class="block text-[10px] font-semibold uppercase tracking-wide text-emerald-500">settled</span>
                    @endif
                </span>
                <svg class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </a>
        @endforeach
    </div>
@endsection
