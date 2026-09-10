@extends('layouts.admin')

@section('title', __('mdl.dashboard'))
@section('heading', __('mdl.dashboard'))
@section('subheading', now()->translatedFormat('l, j F Y'))

@section('content')
    {{-- KPI grid. Seven figures an operator checks before anything else; the two that
         compare against the previous cycle carry the delta in the sub-line. --}}
    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $stat)
            @php
                $tone = $stat['tone'];
                $tile = match ($tone) {
                    'danger' => 'tile-danger',
                    'success' => 'tile-success',
                    'warning' => 'tile-primary',
                    'info' => 'tile-info',
                    default => 'tile-muted',
                };
                $value = match ($tone) {
                    'danger' => 'text-destructive',
                    'success' => 'text-green-700 dark:text-green-500',
                    'warning' => 'text-amber-700 dark:text-amber-400',
                    default => 'text-mono',
                };
            @endphp
            <div class="kt-card">
                <div class="kt-card-content">
                    <div class="flex items-start justify-between gap-2.5">
                        <span class="text-sm font-medium text-muted-foreground">{{ $stat['label'] }}</span>
                        <span class="tile {{ $tile }} size-9 text-base">
                            <i class="ki-filled {{ $stat['icon'] }}"></i>
                        </span>
                    </div>
                    <div class="amount-cell mt-2 text-2xl font-semibold {{ $value }}">{{ $stat['value'] }}</div>
                    <div class="mt-1 text-xs text-muted-foreground">{{ $stat['sub'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        {{-- Recent payments --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">{{ __('dash.recent_payments') }}</h3>
                @if (\Illuminate\Support\Facades\Route::has('admin.payments.index'))
                    <a href="{{ route('admin.payments.index') }}" class="kt-btn kt-btn-sm kt-btn-ghost">
                        {{ __('mdl.payments') }}
                        <i class="ki-filled ki-arrow-right rtl:rotate-180"></i>
                    </a>
                @endif
            </div>
            <div class="kt-card-content py-1.5">
                @if ($recentPayments->isEmpty())
                    <p class="py-6 text-center text-sm text-muted-foreground">{{ __('dash.no_payments') }}</p>
                @else
                    <ul class="divide-y divide-border">
                        @foreach ($recentPayments as $payment)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <div class="min-w-0 leading-tight">
                                    <div class="truncate text-sm font-medium text-mono">
                                        {{ $payment->subscriber?->name ?? '—' }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-muted-foreground">
                                        <span dir="ltr">{{ $payment->number }}</span>
                                        · {{ $payment->methodLabel() }}
                                        · {{ $payment->collector?->name ?? __('col.unknown_collector') }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-end">
                                    <div class="amount-cell text-sm font-semibold text-green-700 dark:text-green-500">
                                        {{ money($payment->amount) }}
                                    </div>
                                    <div class="text-2xs text-muted-foreground">{{ $payment->created_at->format('Y-m-d H:i') }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- Largest debtors --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">{{ __('dash.top_debtors') }}</h3>
                @if (\Illuminate\Support\Facades\Route::has('admin.overdue-report'))
                    <a href="{{ route('admin.overdue-report') }}" class="kt-btn kt-btn-sm kt-btn-ghost">
                        {{ __('mdl.overdue_report') }}
                        <i class="ki-filled ki-arrow-right rtl:rotate-180"></i>
                    </a>
                @endif
            </div>
            <div class="kt-card-content py-1.5">
                @if ($topDebtors->isEmpty())
                    <p class="py-6 text-center text-sm text-muted-foreground">{{ __('dash.no_debtors') }}</p>
                @else
                    <ul class="divide-y divide-border">
                        @foreach ($topDebtors as $debtor)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <div class="min-w-0 leading-tight">
                                    <a href="{{ route('admin.subscribers.show', $debtor->subscriber_id) }}"
                                       class="truncate text-sm font-medium text-mono transition hover:text-amber-700 dark:hover:text-amber-400">
                                        {{ $debtor->subscriber_name }}
                                    </a>
                                    <div class="mt-0.5 text-xs text-muted-foreground">
                                        {{ $debtor->route_name ?: __('col.no_route') }}
                                        · {{ plural('t.bills_open', (int) $debtor->bills_open) }}
                                    </div>
                                </div>
                                <div class="amount-cell shrink-0 text-sm font-semibold text-destructive">
                                    {{ money($debtor->owed) }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection
