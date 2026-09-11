@php
    // The two questions this list cannot answer, surfaced above it.
    //
    // This screen shows the readings that EXIST; "who is still missing" and "which meter is dead"
    // are different questions with their own screens. Without these two doors the operator reads a
    // list of what is done and has no way to see what is not — which is exactly how a missing
    // month went unnoticed.
    $boardPeriod = \App\Services\BillingService::currentPeriod();
    $progress = \App\Services\BillingService::readingProgress($boardPeriod);
    $openCases = \App\Models\Subscriber::query()
        ->metered()
        ->active()
        ->whereNotNull('meter_broken_reported_at')
        ->count();
@endphp

<div class="mb-4 grid gap-3 sm:grid-cols-2">
    @if (\Illuminate\Support\Facades\Route::has('admin.readings-board'))
        <a href="{{ route('admin.readings-board') }}"
           class="kt-card group transition hover:border-primary/40">
            <div class="kt-card-content flex items-center gap-3">
                <span class="tile {{ $progress['pending'] > 0 ? 'tile-warning' : 'tile-success' }} size-10 text-lg">
                    <i class="ki-filled ki-bill"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-2xs font-semibold uppercase tracking-widest text-muted-foreground">
                        {{ __('rdgl.pending_card') }}
                    </span>
                    <span class="block text-lg font-semibold tabular-nums {{ $progress['pending'] > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-green-700 dark:text-green-500' }}">
                        {{ number_format($progress['pending']) }}
                    </span>
                    <span class="block truncate text-xs text-muted-foreground">
                        {{ __('rdgl.pending_hint', [
                            'done' => number_format($progress['recorded']),
                            'total' => number_format($progress['total']),
                        ]) }}
                    </span>
                </span>
                <i class="ki-filled ki-arrow-right shrink-0 text-muted-foreground rtl:rotate-180"></i>
            </div>
        </a>
    @endif

    @if (\Illuminate\Support\Facades\Route::has('admin.meter-cases'))
        <a href="{{ route('admin.meter-cases') }}"
           class="kt-card group transition hover:border-primary/40">
            <div class="kt-card-content flex items-center gap-3">
                <span class="tile {{ $openCases > 0 ? 'tile-danger' : 'tile-success' }} size-10 text-lg">
                    <i class="ki-filled ki-shield-cross"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-2xs font-semibold uppercase tracking-widest text-muted-foreground">
                        {{ __('rdgl.cases_card') }}
                    </span>
                    <span class="block text-lg font-semibold tabular-nums {{ $openCases > 0 ? 'text-destructive' : 'text-green-700 dark:text-green-500' }}">
                        {{ number_format($openCases) }}
                    </span>
                    <span class="block truncate text-xs text-muted-foreground">{{ __('rdgl.cases_hint') }}</span>
                </span>
                <i class="ki-filled ki-arrow-right shrink-0 text-muted-foreground rtl:rotate-180"></i>
            </div>
        </a>
    @endif
</div>
