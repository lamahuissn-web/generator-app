@extends('layouts.admin')

@section('title', $subscriber->name)
@section('heading', $subscriber->name)
@section('subheading', $subscriber->route?->name ?? __('col.no_route'))

@section('content')
    @php
        $initials = str($subscriber->name)->trim()->explode(' ')->filter()->take(2)
            ->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
    @endphp

    {{-- Identity + balance: what the operator needs before any action --}}
    <div class="mb-5 grid gap-4 lg:grid-cols-3">
        <div class="kt-card lg:col-span-2">
            <div class="kt-card-content">
                <div class="flex items-start gap-3">
                    <span class="tile tile-primary size-12 text-base font-semibold">{{ $initials ?: '?' }}</span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <h1 class="text-lg font-semibold leading-tight text-mono">{{ $subscriber->name }}</h1>
                            <span class="kt-badge kt-badge-sm {{ $subscriber->isMetered() ? 'kt-badge-info' : 'kt-badge-outline' }}">
                                {{ $subscriber->isMetered() ? __('t.type_metered') : __('t.type_flat') }}
                            </span>
                            @if ($subscriber->status === \App\Models\Subscriber::STATUS_ACTIVE)
                                <span class="kt-badge kt-badge-sm kt-badge-success">{{ __('portal.active') }}</span>
                            @else
                                <span class="kt-badge kt-badge-sm kt-badge-outline">{{ __('portal.inactive') }}</span>
                            @endif
                            @if ($subscriber->hasBrokenMeterReport())
                                <span class="kt-badge kt-badge-sm kt-badge-destructive">{{ __('t.meter_broken_badge') }}</span>
                            @endif
                        </div>

                        <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-muted-foreground sm:grid-cols-3">
                            @if ($subscriber->meter_no)
                                <span class="flex items-center gap-1">
                                    <i class="ki-filled ki-bill"></i>
                                    {{ __('t.meter') }} <span dir="ltr">{{ $subscriber->meter_no }}</span>
                                </span>
                            @endif
                            @if ($subscriber->amperage)
                                <span class="flex items-center gap-1">
                                    <i class="ki-filled ki-flash-circle"></i>
                                    <span dir="ltr">{{ $subscriber->amperage }}A</span>
                                </span>
                            @endif
                            @if ($subscriber->phone)
                                <a href="tel:{{ preg_replace('/\s+/', '', $subscriber->phone) }}" class="flex items-center gap-1 transition hover:text-mono">
                                    <i class="ki-filled ki-phone"></i>
                                    <span dir="ltr">{{ $subscriber->phone }}</span>
                                </a>
                            @endif
                            @if ($subscriber->address)
                                <span class="col-span-2 flex items-center gap-1 sm:col-span-3">
                                    <i class="ki-filled ki-geolocation"></i>{{ $subscriber->address }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- The subscriber's own actions. Each is one nested component: a trigger
                     button plus the Metronic modal it opens, so nothing is initialised until
                     the operator reaches for it. --}}
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <livewire:admin.action-form
                        :action="\App\Admin\Actions\CollectPayment::class"
                        :subject-id="$subscriber->id"
                        :key="'collect-'.$subscriber->id" />

                    <livewire:admin.action-form
                        :action="\App\Admin\Actions\SpecialInvoice::class"
                        :subject-id="$subscriber->id"
                        :key="'special-'.$subscriber->id" />

                    @if ($subscriber->isMetered())
                        <livewire:admin.action-form
                            :action="\App\Admin\Actions\ConvertToFlat::class"
                            :subject-id="$subscriber->id"
                            :key="'toflat-'.$subscriber->id" />
                    @else
                        <livewire:admin.action-form
                            :action="\App\Admin\Actions\ConvertToMetered::class"
                            :subject-id="$subscriber->id"
                            :key="'tometer-'.$subscriber->id" />
                    @endif

                    <a href="{{ route('admin.subscribers.edit', $subscriber) }}" class="kt-btn kt-btn-sm kt-btn-outline">
                        <i class="ki-filled ki-pencil"></i>
                        {{ __('t.edit') }}
                    </a>
                    <a href="{{ route('print.statement', ['subscriber' => $subscriber->id, 'size' => 'a4']) }}"
                       target="_blank" rel="noopener" class="kt-btn kt-btn-sm kt-btn-outline">
                        <i class="ki-filled ki-printer"></i>
                        {{ __('col.statement') }}
                    </a>
                    <a href="{{ route('admin.subscribers.index') }}" class="kt-btn kt-btn-sm kt-btn-ghost">
                        <i class="ki-filled ki-arrow-left rtl:rotate-180"></i>
                        {{ __('mdl.subscribers') }}
                    </a>
                </div>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-content flex flex-col gap-1">
                <span class="text-2xs font-semibold uppercase tracking-widest text-muted-foreground">
                    {{ __('t.outstanding_bal') }}
                </span>
                <span class="amount-cell text-3xl font-semibold {{ $outstanding > 0 ? 'text-destructive' : 'text-green-700 dark:text-green-500' }}">
                    {{ money($outstanding) }}
                </span>
                <span class="text-xs text-muted-foreground">
                    @if ($unpaidCount > 0)
                        {{ plural('t.bills_open', $unpaidCount) }}
                    @else
                        {{ __('portal.balance_clear') }}
                    @endif
                </span>
            </div>
        </div>
    </div>

    {{-- Tabs: the three records that make up the account. Each tab is the same table
         component the list pages use, handed a constraint. --}}
    <div class="mb-4 grid grid-cols-3 gap-1.5 rounded-lg bg-muted p-1.5 sm:w-fit">
        @foreach ([
            'bills' => ['label' => __('mdl.bills'), 'icon' => 'ki-receipt-square'],
            'readings' => ['label' => __('mdl.readings'), 'icon' => 'ki-bill'],
            'payments' => ['label' => __('mdl.payments'), 'icon' => 'ki-wallet'],
        ] as $key => $meta)
            <a href="{{ route('admin.subscribers.show', ['subscriber' => $subscriber, 'tab' => $key]) }}"
               @class([
                   'kt-btn kt-btn-sm justify-center',
                   '' => $tab === $key,
                   'kt-btn-ghost' => $tab !== $key,
               ])>
                <i class="ki-filled {{ $meta['icon'] }}"></i>
                <span>{{ $meta['label'] }}</span>
            </a>
        @endforeach
    </div>

    @if ($tab === 'bills')
        <livewire:admin.data-table
            :table="\App\Admin\Tables\BillsTable::class"
            :constraints="['subscriber_id' => $subscriber->id]"
            :key="'bills-'.$subscriber->id" />
    @elseif ($tab === 'readings')
        <livewire:admin.data-table
            :table="\App\Admin\Tables\ReadingsTable::class"
            :constraints="['subscriber_id' => $subscriber->id]"
            :key="'readings-'.$subscriber->id" />
    @else
        <livewire:admin.data-table
            :table="\App\Admin\Tables\PaymentsTable::class"
            :constraints="['subscriber_id' => $subscriber->id]"
            :key="'payments-'.$subscriber->id" />
    @endif
@endsection
