@extends('layouts.collector')

@section('title', $route->name)

@section('content')
    @php
        // Route total: sum of what every subscriber on this route still owes.
        $routeDue = collect($subscribers)->sum(fn ($s) => (float) ($owed[$s->id] ?? 0));
    @endphp

    {{-- Route heading --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('collector.dashboard') }}" class="kt-btn kt-btn-icon kt-btn-outline">
                <i class="ki-filled ki-arrow-left rtl:rotate-180"></i>
            </a>
            <div class="min-w-0">
                <h1 class="truncate text-xl font-medium leading-none text-mono">{{ $route->name }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ plural('portal.route_subscribers', $subscribers->count()) }}
                    @if ($route->notes) · {{ $route->notes }} @endif
                </p>
            </div>
        </div>
        @if ($routeDue > 0)
            <span class="kt-badge kt-badge-lg kt-badge-outline kt-badge-destructive">
                <i class="ki-filled ki-dollar"></i>
                {{ money($routeDue) }}
            </span>
        @endif
    </div>

    @if ($subscribers->isEmpty())
        <div class="kt-card border-dashed">
            <div class="kt-card-content text-center text-sm text-muted-foreground">
                {{ plural('portal.route_subscribers', 0) }}
            </div>
        </div>
    @endif

    <div class="grid gap-2.5">
        @foreach ($subscribers as $subscriber)
            @php
                // NB: keep this name distinct from the $owed lookup array handed in by the
                // controller — reusing $owed here overwrote it on the first row, so every
                // later subscriber read a float as an array and silently showed $0 "settled".
                $subscriberDue = (float) ($owed[$subscriber->id] ?? 0);
                $inactive = $subscriber->status === 'inactive';
            @endphp
            <a href="{{ route('collector.subscriber.show', $subscriber) }}"
               class="kt-card group transition hover:border-primary/40 {{ $inactive ? 'opacity-60' : '' }}">
                <div class="kt-card-content flex items-center gap-3">
                    <span class="tile {{ $subscriber->type === 'metered' ? 'tile-info' : 'tile-violet' }} size-10 text-2xs font-semibold">
                        {{ $subscriber->type === 'metered' ? 'MTR' : 'FLT' }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate font-semibold text-mono">{{ $subscriber->name }}</span>
                            @if ($inactive)
                                <span class="kt-badge kt-badge-xs kt-badge-outline shrink-0">{{ __('portal.inactive') }}</span>
                            @endif
                        </span>
                        <span class="block truncate text-xs text-muted-foreground">
                            {{ collect([$subscriber->meter_no ? __('portal.meter').' '.ltr_isolate($subscriber->meter_no) : null, $subscriber->amperage ? ltr_isolate($subscriber->amperage.'A') : null, $subscriber->address])->filter()->implode(' · ') }}
                        </span>
                    </span>

                    <span class="amount-cell shrink-0 text-right">
                        @if ($subscriberDue > 0)
                            <span class="block font-semibold text-destructive">{{ money($subscriberDue) }}</span>
                            <span class="block text-2xs font-medium uppercase tracking-wide text-muted-foreground">{{ __('portal.due') }}</span>
                        @else
                            <span class="block font-semibold text-green-600">0</span>
                            <span class="block text-2xs font-medium uppercase tracking-wide text-green-600">{{ __('portal.settled') }}</span>
                        @endif
                    </span>

                    <i class="ki-filled ki-arrow-right shrink-0 text-muted-foreground rtl:rotate-180"></i>
                </div>
            </a>
        @endforeach
    </div>
@endsection
