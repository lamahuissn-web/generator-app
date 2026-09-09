@extends('layouts.collector')

@section('title', 'Dashboard')

@section('content')
    @php
        $hour = (int) now()->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    @endphp

    {{-- Greeting + shift summary --}}
    <section class="mb-5 overflow-hidden rounded-2xl bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 p-5 text-white shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs font-medium uppercase tracking-widest text-slate-400">{{ now()->format('l, M j') }}</p>
                <h1 class="mt-1 text-xl font-bold">{{ $greeting }}, {{ auth()->user()->name }}</h1>
            </div>
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-amber-400/15 text-xl text-amber-300">📋</span>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3">
            <div class="rounded-xl bg-white/5 p-3 ring-1 ring-white/10">
                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Collected today</p>
                <p class="mt-1 text-lg font-bold tabular-nums text-emerald-400">{{ money($stats['today_total']) }}</p>
                <p class="text-[11px] text-slate-500">{{ $stats['today_count'] }} payments</p>
            </div>
            <div class="rounded-xl bg-white/5 p-3 ring-1 ring-white/10">
                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">This month</p>
                <p class="mt-1 text-lg font-bold tabular-nums text-amber-300">{{ money($stats['month_total']) }}</p>
                <p class="text-[11px] text-slate-500">{{ $stats['month_count'] }} payments</p>
            </div>
        </div>
    </section>

    {{-- Global search --}}
    <livewire:collector.global-search />

    {{-- Routes --}}
    <section>
        <div class="mb-2.5 flex items-baseline justify-between px-1">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Routes</h2>
            <span class="text-xs text-slate-400">{{ $routes->count() }} areas</span>
        </div>

        @if ($routes->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-400">
                No routes yet — add them from the admin panel.
            </div>
        @endif

        <div class="space-y-2.5">
            @foreach ($routes as $route)
                @php $owed = (float) ($owedByRoute[$route->id] ?? 0); @endphp
                <a href="{{ route('collector.route.show', $route) }}"
                   class="group flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-amber-300 hover:shadow-md">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-slate-100 text-lg text-slate-500 transition group-hover:bg-amber-100 group-hover:text-amber-600">🗺️</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-bold text-slate-900">{{ $route->name }}</span>
                        <span class="block text-xs text-slate-500">
                            {{ $route->active_count }} active · {{ $route->subscribers_count }} total
                            @if ($owed > 0)
                                <span class="font-semibold text-rose-500">· {{ money($owed) }} due</span>
                            @endif
                        </span>
                    </span>
                    <svg class="h-5 w-5 shrink-0 text-slate-300 transition group-hover:text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            @endforeach
        </div>
    </section>
@endsection
