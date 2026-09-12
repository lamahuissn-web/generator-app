@extends('layouts.admin')

@section('title', $subscriber->name)
@section('heading', $subscriber->name)
@section('subheading', $subscriber->route?->name ?? __('col.no_route'))

@section('content')
    @php
        $initials = str($subscriber->name)->trim()->explode(' ')->filter()->take(2)
            ->map(fn ($p) => mb_substr($p, 0, 1))->implode('');

        // The URL this page was rendered at, handed to every action modal so the redirect after
        // an action returns HERE — including the cycle being read. Taken from the incoming
        // request rather than the request's Referer header, which a client can forge or omit.
        // (fullUrl() lives on the Request, not on url() — UrlGenerator offers current()/full().)
        $returnTo = request()->fullUrl();

        // How the file is being read, said once and reused by every card and section heading, so
        // no two parts of the page can disagree about which cycle they are showing.
        $scope = $period ? period_label_i18n($period) : __('file.all_cycles');

        // The three records the account is made of. Each is the same table component the list
        // pages use, handed the subscriber constraint, the cycle, and `compact` — the page above
        // has already fixed the scope, so a second search box over nine rows is noise.
        $logs = [
            'bills' => ['table' => \App\Admin\Tables\SubscriberBillsTable::class, 'title' => 'file.bills_log', 'icon' => 'ki-receipt-square'],
            'readings' => ['table' => \App\Admin\Tables\SubscriberReadingsTable::class, 'title' => 'file.readings_log', 'icon' => 'ki-bill'],
            'payments' => ['table' => \App\Admin\Tables\SubscriberPaymentsTable::class, 'title' => 'file.payments_log', 'icon' => 'ki-wallet'],
        ];
    @endphp

    {{-- Identity + actions, and the cycle the whole file below is read as of --}}
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
                        :return-to="$returnTo"
                        :key="'collect-'.$subscriber->id" />

                    {{-- Recording the reading is the visit; it belongs next to collecting,
                         since a collector does both in one stop. --}}
                    <livewire:admin.action-form
                        :action="\App\Admin\Actions\RecordReading::class"
                        :subject-id="$subscriber->id"
                        :return-to="$returnTo"
                        :key="'reading-'.$subscriber->id" />

                    <livewire:admin.action-form
                        :action="\App\Admin\Actions\SpecialInvoice::class"
                        :subject-id="$subscriber->id"
                        :return-to="$returnTo"
                        :key="'special-'.$subscriber->id" />

                    @if ($subscriber->isMetered())
                        <livewire:admin.action-form
                            :action="\App\Admin\Actions\ConvertToFlat::class"
                            :subject-id="$subscriber->id"
                            :return-to="$returnTo"
                            :key="'toflat-'.$subscriber->id" />
                    @else
                        <livewire:admin.action-form
                            :action="\App\Admin\Actions\ConvertToMetered::class"
                            :subject-id="$subscriber->id"
                            :return-to="$returnTo"
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

        {{-- Which cycle the file is read as of. A plain GET form rather than Livewire state: the
             cycle belongs in the URL, so a link to "this subscriber in August" opens in August,
             and the back button walks the cycles that were looked at. --}}
        <div class="kt-card">
            <div class="kt-card-content flex flex-col gap-2">
                <span class="text-2xs font-semibold uppercase tracking-widest text-muted-foreground">
                    {{ __('file.filter_label') }}
                </span>

                <form method="GET" action="{{ route('admin.subscribers.show', $subscriber) }}">
                    <select name="period" class="kt-select w-full" onchange="this.form.submit()"
                            aria-label="{{ __('file.filter_label') }}">
                        <option value="">{{ __('file.all_cycles') }}</option>
                        @foreach ($periods as $key => $label)
                            <option value="{{ $key }}" @selected($period === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>

                <span class="text-xs text-muted-foreground">{{ __('file.filter_hint') }}</span>
            </div>
        </div>
    </div>

    {{-- The three numbers the phone call is about, in the cycle being read. --}}
    <div class="mb-5 grid gap-4 sm:grid-cols-3">
        <div class="kt-card">
            <div class="kt-card-content">
                <div class="flex items-start justify-between gap-2">
                    <span class="text-2xs font-semibold uppercase tracking-widest text-muted-foreground">{{ __('file.billed') }}</span>
                    <span class="tile tile-muted size-9 text-sm"><i class="ki-filled ki-receipt-square"></i></span>
                </div>
                <div class="amount-cell mt-2 text-2xl font-semibold text-mono">{{ money($totals['billed']) }}</div>
                <div class="mt-1 text-xs text-muted-foreground">
                    {{ $scope }} · {{ plural('file.records', $counts['bills']) }}
                </div>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-content">
                <div class="flex items-start justify-between gap-2">
                    <span class="text-2xs font-semibold uppercase tracking-widest text-muted-foreground">{{ __('file.paid') }}</span>
                    <span class="tile tile-muted size-9 text-sm"><i class="ki-filled ki-dollar"></i></span>
                </div>
                <div class="amount-cell mt-2 text-2xl font-semibold text-green-700 dark:text-green-500">{{ money($totals['paid']) }}</div>
                <div class="mt-1 text-xs text-muted-foreground">
                    {{ $scope }} · {{ plural('file.records', $counts['payments']) }}
                </div>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-content">
                <div class="flex items-start justify-between gap-2">
                    <span class="text-2xs font-semibold uppercase tracking-widest text-muted-foreground">{{ __('file.due') }}</span>
                    <span class="tile tile-muted size-9 text-sm"><i class="ki-filled ki-wallet"></i></span>
                </div>
                <div class="amount-cell mt-2 text-2xl font-semibold {{ $totals['due'] > 0 ? 'text-destructive' : 'text-green-700 dark:text-green-500' }}">
                    {{ money($totals['due']) }}
                </div>
                <div class="mt-1 text-xs text-muted-foreground">
                    @if ($period && abs($totals['other_cycles_due']) > 0.005)
                        {{-- A cycle-scoped card must not let the rest of the account disappear:
                             the whole debt is the number the subscriber will be asked for. --}}
                        {{ __('file.other_cycles_due', ['amount' => money_ltr($totals['other_cycles_due'])]) }}
                    @elseif ($openBills > 0)
                        {{ plural('t.bills_open', $openBills) }}
                    @else
                        {{ __('portal.balance_clear') }}
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- The three logs. Each heading carries its own count, because "٣ سجلات" is the answer to
         "عندك شي عليه؟" before the list is even read. --}}
    <div class="flex flex-col gap-5">
        @foreach ($logs as $key => $log)
            <section>
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <i class="ki-filled {{ $log['icon'] }} text-muted-foreground"></i>
                    <h2 class="text-sm font-semibold text-mono">{{ __($log['title']) }}</h2>
                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ plural('file.records', $counts[$key]) }}</span>
                    <span class="text-xs text-muted-foreground">{{ $scope }}</span>
                </div>

                <livewire:admin.data-table
                    :table="$log['table']"
                    :constraints="['subscriber_id' => $subscriber->id]"
                    :compact="true"
                    :period="$period"
                    :key="$key.'-'.$subscriber->id.'-'.($period ?? 'all')" />
            </section>
        @endforeach
    </div>
@endsection
