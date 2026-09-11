<div>
    {{-- Trigger. The modal itself is only in the DOM while open, so nothing has to be
         initialised on load and no backdrop can outlive the list under it. --}}
    <button type="button" wire:click="open" class="kt-btn kt-btn-sm {{ $definition->triggerClass() }}">
        @if ($definition->icon())
            <i class="ki-filled {{ $definition->icon() }}"></i>
        @endif
        {{ $definition->label() }}
    </button>

    @if ($open)
        <div class="kt-modal is-live" role="dialog" aria-modal="true" wire:key="action-modal">
            <div class="kt-modal-backdrop" wire:click="close"></div>

            {{-- w-full is load-bearing: .kt-modal.is-live is a flex row, so without it this
                 wrapper shrinks to the dialog's width and sits at the flex start — which in
                 RTL is the RIGHT edge of the screen, not the centre. --}}
            <div class="relative flex min-h-full w-full items-center justify-center">
                <div class="kt-modal-content w-full max-w-xl">
                    <div class="kt-modal-header">
                        <h3 class="kt-modal-title">{{ $definition->modalHeading($subject) }}</h3>
                        <button type="button" class="kt-modal-close" wire:click="close" aria-label="{{ __('t.close') }}">
                            <i class="ki-filled ki-cross"></i>
                        </button>
                    </div>

                    <form wire:submit="submit">
                        <div class="kt-modal-body grid gap-4 sm:grid-cols-2">
                            @if ($definition->modalDescription())
                                <p class="text-sm text-muted-foreground sm:col-span-2">
                                    {{ $definition->modalDescription() }}
                                </p>
                            @endif

                            @error('action')
                                {{-- A refused business rule, not a crash: the service's own words,
                                     with the modal left open so the operator can correct the input. --}}
                                <div class="kt-alert kt-alert-light kt-alert-destructive sm:col-span-2" role="alert">
                                    <div class="kt-alert-icon"><i class="ki-filled ki-information text-base"></i></div>
                                    <div class="kt-alert-content">
                                        <div class="kt-alert-title">{{ $message }}</div>
                                    </div>
                                </div>
                            @enderror

                            @foreach ($sections as $section)
                                @php
                                    $visibleFields = collect($section['fields'])
                                        ->filter(fn (array $f) => $this->shows($f))->values();
                                @endphp
                                @continue ($visibleFields->isEmpty())

                                @if (! empty($section['title']) && count($sections) > 1)
                                    <div class="flex flex-col gap-0.5 sm:col-span-2">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                            {{ $section['title'] }}
                                        </span>
                                        @if (! empty($section['description']))
                                            <span class="text-xs text-muted-foreground">{{ $section['description'] }}</span>
                                        @endif
                                    </div>
                                @endif

                                @foreach ($visibleFields as $field)
                                    <x-admin.field :field="$field" model="state" :values="$state" id-prefix="a" />
                                @endforeach
                            @endforeach
                        </div>

                        <div class="kt-modal-footer">
                            <button type="button" wire:click="close" class="kt-btn kt-btn-outline">
                                {{ __('t.cancel') }}
                            </button>
                            <button type="submit" wire:loading.attr="disabled" class="kt-btn">
                                <i class="ki-filled ki-check"></i>
                                {{ $definition->submitLabel() }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <datalist id="item-presets">
            @foreach (\App\Models\BillItem::$presets as $preset)
                <option value="{{ $preset }}"></option>
            @endforeach
        </datalist>
    @endif
</div>
