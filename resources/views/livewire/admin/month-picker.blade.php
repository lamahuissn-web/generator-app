@php
    // Server-rendered marks: nothing here depends on a browser variable, so a click always means the
    // month it says. See the class docblock — the first version died precisely because these were
    // client-side.
    $nowYear = (int) substr($now, 0, 4);
    $nowMonth = (int) substr($now, 5, 2);
    $chosenYear = $chosen ? (int) substr($chosen, 0, 4) : 0;
    $chosenMonth = $chosen ? (int) substr($chosen, 5, 2) : 0;
@endphp

{{-- $attributes is load-bearing: the host screen puts its own listener here
     (`x-on:month-picked="$wire.set('filters.period', ...)"`), and a child component that does not
     merge its attributes is a child nothing can hear. --}}
<div {{ $attributes->merge(['class' => 'relative']) }}
     {{-- Closing when the operator clicks away is the one thing Alpine is still for: it is a UI
          gesture with no meaning to store, and the app already uses Alpine for the sidebar. --}}
     x-on:click.outside="$wire.close()"
     x-on:keydown.escape.window="$wire.close()">

    <button type="button"
            class="kt-select kt-select-sm inline-flex w-auto items-center justify-between gap-2 text-start"
            wire:click="toggle"
            aria-haspopup="true"
            :aria-expanded="@js($open)">
        <span @class(['whitespace-nowrap', 'text-muted-foreground' => $chosen === null])>
            {{ $chosen ? period_label_i18n($chosen) : $placeholder }}
        </span>
        <i class="ki-filled ki-down text-muted-foreground" aria-hidden="true"></i>
    </button>

    @if ($open)
        {{-- Metronic's own contract for a dropdown panel is the `open` class
             (`.kt-dropdown:not(.open){display:none}`), and it is a literal class here because the
             grid is only rendered while it is open. --}}
        <div class="kt-dropdown open absolute start-0 z-30 mt-1 p-4">

            <div class="mb-3 flex items-center justify-between gap-2">
                <button type="button" class="kt-btn kt-btn-icon kt-btn-outline"
                        wire:click="step(-1)"
                        @disabled($year <= $fromYear)
                        @class(['opacity-40' => $year <= $fromYear])
                        title="{{ __('t.prev_year') }}">
                    <i class="ki-filled ki-right" aria-hidden="true"></i>
                </button>

                <span class="text-lg font-semibold tabular-nums">{{ $year }}</span>

                <button type="button" class="kt-btn kt-btn-icon kt-btn-outline"
                        wire:click="step(1)"
                        @disabled($year >= $toYear)
                        @class(['opacity-40' => $year >= $toYear])
                        title="{{ __('t.next_year') }}">
                    <i class="ki-filled ki-left" aria-hidden="true"></i>
                </button>
            </div>

            <div class="grid grid-cols-3 gap-2">
                @foreach ($names as $number => $name)
                    @php
                        $month = sprintf('%04d-%02d', $year, $number);
                        $isChosen = $month === $chosen;
                        $isNow = $year === $nowYear && $number === $nowMonth;
                    @endphp

                    <button type="button"
                            wire:click="choose({{ $number }})"
                            @class([
                                'relative rounded-lg border px-3 py-2 text-sm transition',
                                'border-primary bg-primary text-primary-foreground' => $isChosen,
                                'border-border' => ! $isChosen && $isNow,
                                'border-transparent hover:bg-muted/50' => ! $isChosen && ! $isNow,
                            ])
                            title="{{ $name.' '.$year }}">
                        {{ $name }}

                        {{-- "There is no June in this program" and "June is here and empty" are
                             different facts; an operator looking at an empty month should know which
                             one they are looking at. --}}
                        @if (in_array($month, $monthsWithData, true))
                            {{-- A short bar, not a dot: a 4px dot read as a rendering artefact and an 8px
                                 one was still missed, so this is 12×4 with the accent colour — legible
                                 at a glance from where an operator sits. On the filled cell it has to
                                 read AGAINST the fill rather than merge into it. --}}
                            <span @class([
                                    'absolute bottom-0 start-0 end-0 mx-auto h-1 w-3 rounded-full',
                                    'bg-card' => $isChosen,
                                    'bg-primary' => ! $isChosen,
                                ])
                                aria-hidden="true"></span>
                        @endif
                    </button>
                @endforeach
            </div>

            <p class="mt-3 flex items-center gap-2 border-t border-border pt-3 text-xs text-muted-foreground">
                <span class="h-1 w-3 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                {{ __('t.month_dot_hint') }}
            </p>
        </div>
    @endif
</div>
