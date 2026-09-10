@extends('layouts.collector')

@section('title', __('portal.routes'))

@section('content')
    @php
        $hour = (int) now()->format('G');
        $greeting = $hour < 12 ? __('portal.gm') : ($hour < 18 ? __('portal.ga') : __('portal.ge'));
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
                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ __('portal.today_cash') }}</p>
                <p class="mt-1 text-lg font-bold tabular-nums text-emerald-400">{{ money($stats['today_total']) }}</p>
                <p class="text-[11px] text-slate-500">{{ __('portal.today_payments', ['n' => $stats['today_count']]) }}</p>
            </div>
            <div class="rounded-xl bg-white/5 p-3 ring-1 ring-white/10">
                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ __('portal.month_cash') }}</p>
                <p class="mt-1 text-lg font-bold tabular-nums text-amber-300">{{ money($stats['month_total']) }}</p>
                <p class="text-[11px] text-slate-500">{{ __('portal.today_payments', ['n' => $stats['month_count']]) }}</p>
            </div>
        </div>
    </section>

    {{-- Cash reconciliation for this collector --}}
    <section class="mb-5 rounded-2xl border p-5 shadow-sm {{ $stats['in_hand'] > 0 ? 'border-amber-200 bg-gradient-to-br from-amber-50 to-white' : 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-white' }}">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-widest text-slate-500">{{ __('portal.in_hand') }}</p>
                <p class="mt-1 text-3xl font-extrabold tabular-nums {{ $stats['in_hand'] > 0 ? 'text-amber-600' : 'text-emerald-600' }}">
                    {{ money($stats['in_hand']) }}
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    {{ __('portal.in_hand_body', ['handed' => money($stats['handed_total'])]) }}
                </p>
            </div>
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white text-xl shadow-sm">{{ $stats['in_hand'] > 0 ? '💰' : '✅' }}</span>
        </div>

        @if ($handovers->isNotEmpty())
            <ul class="mt-4 space-y-1.5 border-t border-dashed border-slate-200 pt-3">
                @foreach ($handovers as $handover)
                    <li class="flex items-center justify-between text-xs">
                        <span class="text-slate-500">{{ $handover->handover_date->format('Y-m-d') }}{{ $handover->note ? ' · '.$handover->note : '' }}</span>
                        <span class="font-bold tabular-nums text-slate-700">{{ money($handover->amount) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Global search --}}
    <livewire:collector.global-search />

    {{-- Routes --}}
    <section>
        <div class="mb-2.5 flex items-baseline justify-between px-1">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">{{ __('portal.routes') }}</h2>
            <span class="text-xs text-slate-400">{{ __('portal.areas_count', ['n' => $routes->count()]) }}</span>
        </div>

        @if ($routes->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-400">
                {{ __('portal.no_routes') }}
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
                            {{ __('portal.route_stats', ['active' => $route->active_count, 'total' => $route->subscribers_count]) }}
                            @if ($owed > 0)
                                <span class="font-semibold text-rose-500">· {{ __('portal.due') }} {{ money($owed) }}</span>
                            @endif
                        </span>
                    </span>
                    <svg class="h-5 w-5 shrink-0 text-slate-300 transition group-hover:text-amber-500 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            @endforeach
        </div>
    </section>
@endsection
