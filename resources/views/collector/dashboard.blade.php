@extends('layouts.collector')

@section('title', __('portal.routes'))

@section('content')
    @php
        $hour = (int) now()->format('G');
        $greeting = $hour < 12 ? __('portal.gm') : ($hour < 18 ? __('portal.ga') : __('portal.ge'));
    @endphp

    {{-- Page heading --}}
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-medium leading-none text-mono">{{ $greeting }}, {{ auth()->user()->name }}</h1>
            <span class="text-sm text-muted-foreground">{{ now()->translatedFormat('l, j F') }}</span>
        </div>
        <span class="kt-badge kt-badge-outline kt-badge-primary">
            <i class="ki-filled ki-calendar"></i>
            {{ now()->format('Y-m-d') }}
        </span>
    </div>

    {{-- Shift totals --}}
    <div class="mb-5 grid grid-cols-2 gap-4">
        <div class="kt-card">
            <div class="kt-card-content flex items-center justify-between gap-2.5">
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-muted-foreground">{{ __('portal.today_cash') }}</span>
                    <span class="text-xl font-semibold tabular-nums text-mono">{{ money($stats['today_total']) }}</span>
                    <span class="text-2xs text-muted-foreground">{{ plural('portal.today_payments', $stats['today_count']) }}</span>
                </div>
                <span class="tile tile-success size-10 text-lg">
                    <i class="ki-filled ki-wallet"></i>
                </span>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-content flex items-center justify-between gap-2.5">
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-muted-foreground">{{ __('portal.month_cash') }}</span>
                    <span class="text-xl font-semibold tabular-nums text-mono">{{ money($stats['month_total']) }}</span>
                    <span class="text-2xs text-muted-foreground">{{ plural('portal.today_payments', $stats['month_count']) }}</span>
                </div>
                <span class="tile tile-primary size-10 text-lg">
                    <i class="ki-filled ki-chart-pie-3"></i>
                </span>
            </div>
        </div>
    </div>

    {{-- Cash still in this collector's pocket --}}
    <div class="kt-card mb-5">
        <div class="kt-card-content flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-col gap-1">
                <span class="text-2xs font-semibold uppercase tracking-widest text-muted-foreground">{{ __('portal.in_hand') }}</span>
                <span class="text-3xl font-semibold tabular-nums {{ $stats['in_hand'] > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-green-700 dark:text-green-500' }}">
                    {{ money($stats['in_hand']) }}
                </span>
                <span class="text-xs text-muted-foreground">{{ __('portal.in_hand_body', ['handed' => money_ltr($stats['handed_total'])]) }}</span>
            </div>
            <span class="kt-badge kt-badge-lg kt-badge-outline {{ $stats['in_hand'] > 0 ? 'kt-badge-warning' : 'kt-badge-success' }}">
                <i class="ki-filled {{ $stats['in_hand'] > 0 ? 'ki-dollar' : 'ki-check-circle' }}"></i>
                {{ $stats['in_hand'] > 0 ? __('portal.due') : __('portal.settled') }}
            </span>
        </div>

        @if ($handovers->isNotEmpty())
            <div class="kt-card-content border-t border-border pt-0">
                <ul class="divide-y divide-border">
                    @foreach ($handovers as $handover)
                        <li class="flex items-center justify-between py-2 text-xs">
                            <span class="text-muted-foreground">
                                {{ $handover->handover_date->format('Y-m-d') }}{{ $handover->note ? ' · '.$handover->note : '' }}
                            </span>
                            <span class="font-semibold tabular-nums text-mono">{{ money($handover->amount) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    {{-- Global search --}}
    <div class="mb-5">
        <livewire:collector.global-search />
    </div>

    {{-- Routes --}}
    <div class="mb-3 flex items-baseline justify-between px-1">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">{{ __('portal.routes') }}</h2>
        <span class="text-xs text-muted-foreground">{{ plural('portal.areas_count', $routes->count()) }}</span>
    </div>

    @if ($routes->isEmpty())
        <div class="kt-card">
            <div class="kt-card-content text-center text-sm text-muted-foreground">{{ __('portal.no_routes') }}</div>
        </div>
    @endif

    <div class="grid gap-2.5">
        @foreach ($routes as $route)
            @php $owed = (float) ($owedByRoute[$route->id] ?? 0); @endphp
            <a href="{{ route('collector.route.show', $route) }}"
               class="kt-card group transition hover:border-primary/40">
                <div class="kt-card-content flex items-center gap-3">
                    {{-- A map glyph in a brand-neutral grey tile reads as an unloaded
                         image placeholder, especially at this size, so the route tile
                         carries the location accent instead. --}}
                    <span class="tile tile-info size-10 text-lg">
                        <i class="ki-filled ki-map"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-mono">{{ $route->name }}</span>
                        <span class="block text-xs text-muted-foreground">
                            {{ __('portal.route_stats', ['active' => ltr_isolate($route->active_count), 'total' => ltr_isolate($route->subscribers_count)]) }}
                        </span>
                    </span>
                    @if ($owed > 0)
                        <span class="shrink-0 text-right">
                            <span class="block font-semibold tabular-nums text-destructive">{{ money($owed) }}</span>
                            <span class="block text-2xs font-medium uppercase tracking-wide text-muted-foreground">{{ __('portal.due') }}</span>
                        </span>
                    @endif
                    <i class="ki-filled ki-arrow-right shrink-0 text-muted-foreground rtl:rotate-180"></i>
                </div>
            </a>
        @endforeach
    </div>
@endsection
