@php
    // Tone -> tile colour, in one place: the icon, the button weight and the title colour of a
    // row all read the same map, so a row cannot be amber in its tile and calm in its wording.
    $tiles = [
        'danger' => 'tile-danger',
        'warning' => 'tile-primary',
        'info' => 'tile-info',
        'muted' => 'tile-muted',
    ];
    $titles = [
        'danger' => 'text-destructive',
        'warning' => 'text-amber-700 dark:text-amber-400',
        'info' => 'text-mono',
        'muted' => 'text-mono',
    ];
@endphp

<div class="grid gap-5">
    {{-- What today has brought in so far. Not the pending list — the cards below are that. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($strip as $figure)
            <x-admin.stat
                :label="$figure['label']"
                :value="$figure['value']"
                :hint="$figure['hint']"
                :icon="$figure['icon']"
                :tone="$figure['tone']" />
        @endforeach
    </div>

    <x-admin.card
        flush
        content-class="py-1.5"
        :title="__('today.worklist')"
        :description="__('today.worklist_sub', ['period' => period_label_i18n($period)])">
        <x-slot:actions>
            {{-- The panel is worked from several places at once (a collector records a payment, a
                 reading arrives), so the screen offers the refresh rather than pretending to be
                 live: a worklist that silently goes stale is worse than one that says when it was
                 read. --}}
            <span @class([
                'kt-badge kt-badge-sm kt-badge-outline',
                'kt-badge-destructive' => $items->isNotEmpty(),
            ])>{{ $items->count() }}</span>
            <button type="button" wire:click="$refresh" class="kt-btn kt-btn-sm kt-btn-ghost">
                <i class="ki-filled ki-arrows-circle"></i>
                {{ __('today.refresh') }}
            </button>
        </x-slot:actions>

        @forelse ($items as $item)
            <div wire:key="today-{{ $item['key'] }}"
                 class="flex items-center gap-3 border-b border-border px-4 py-3 last:border-b-0">
                <span @class(['tile size-10 shrink-0 text-lg', $tiles[$item['tone']] ?? 'tile-muted'])>
                    <i class="ki-filled {{ $item['icon'] }}"></i>
                </span>

                <div class="min-w-0 grow leading-tight">
                    <div @class(['text-sm font-semibold', $titles[$item['tone']] ?? 'text-mono'])>
                        {{ $item['title'] }}
                    </div>

                    @foreach ($item['detail'] as $line)
                        <div class="mt-1 text-xs text-muted-foreground">{{ $line }}</div>
                    @endforeach
                </div>

                @if (\Illuminate\Support\Facades\Route::has($item['route']))
                    <a href="{{ route($item['route']) }}"
                       @class([
                           'kt-btn kt-btn-sm shrink-0',
                           'kt-btn-primary' => $item['tone'] === 'danger',
                           'kt-btn-outline' => $item['tone'] !== 'danger',
                       ])>
                        {{ $item['cta'] }}
                        <i class="ki-filled ki-arrow-right rtl:rotate-180"></i>
                    </a>
                @endif
            </div>
        @empty
            <div class="flex flex-col items-center gap-1 px-4 py-10 text-center">
                <span class="tile tile-success size-10 text-lg">
                    <i class="ki-filled ki-check-circle"></i>
                </span>
                <span class="mt-1 text-sm font-semibold text-mono">{{ __('today.all_done') }}</span>
                <span class="text-xs text-muted-foreground">{{ __('today.all_done_sub') }}</span>
            </div>
        @endforelse
    </x-admin.card>

    @if ($done->isNotEmpty())
        {{-- The receipt for the morning. An office that finished the readings should be able to
             SEE that it finished them — the absence of a row is not evidence of anything. --}}
        <x-admin.card
            flush
            content-class="py-1.5"
            :title="__('today.done')"
            :description="__('today.done_sub')">
            <x-slot:actions>
                <span class="kt-badge kt-badge-success kt-badge-sm kt-badge-outline">
                    {{ $done->count() }}
                </span>
            </x-slot:actions>

            @foreach ($done as $item)
                <div wire:key="today-done-{{ $item['key'] }}"
                     class="flex items-center gap-3 border-b border-border px-4 py-3 last:border-b-0">
                    <span class="tile tile-success size-10 shrink-0 text-lg">
                        <i class="ki-filled ki-check-circle"></i>
                    </span>

                    <div class="min-w-0 grow leading-tight">
                        <div class="text-sm text-muted-foreground">{{ $item['title'] }}</div>

                        @foreach ($item['detail'] as $line)
                            <div class="mt-1 text-xs text-muted-foreground">{{ $line }}</div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </x-admin.card>
    @endif
</div>
