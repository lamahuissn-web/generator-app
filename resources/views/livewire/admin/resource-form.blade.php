<form wire:submit="save" class="grid gap-5">
    @foreach ($sections as $section)
        @php
            $visibleFields = collect($section['fields'])->filter(fn (array $f) => $this->shows($f))->values();
            $sectionVisible = ! isset($section['visible_when']) || ($section['visible_when'])($state);
        @endphp

        @continue (! $sectionVisible || $visibleFields->isEmpty())

        <div class="kt-card">
            @if (! empty($section['title']))
                <div class="kt-card-header">
                    <div class="kt-card-heading">
                        <h3 class="kt-card-title">{{ $section['title'] }}</h3>
                        @if (! empty($section['description']))
                            <span class="kt-card-description">{{ $section['description'] }}</span>
                        @endif
                    </div>
                </div>
            @endif

            <div class="kt-card-content grid gap-4 sm:grid-cols-2">
                @foreach ($visibleFields as $field)
                    @php
                        $key = $field['key'];
                        $full = ($field['span'] ?? 1) === 'full';
                        $type = $field['type'] ?? 'text';
                        $hasError = $errors->has('state.'.$key);
                    @endphp

                    <div @class(['flex flex-col gap-1.5', 'sm:col-span-2' => $full])>
                        <label for="f-{{ $key }}" class="kt-form-label font-normal text-mono">
                            {{ $field['label'] }}
                            @if (in_array('required', $field['rules'] ?? [], true))
                                <span class="text-destructive" aria-hidden="true">*</span>
                            @endif
                        </label>

                        @if ($type === 'select')
                            <select id="f-{{ $key }}" wire:model.live="state.{{ $key }}"
                                    class="kt-select w-full" @disabled(false)>
                                <option value="">{{ __('t.select_placeholder') }}</option>
                                @foreach (($field['options'] ?? (isset($field['options_from']) ? ($field['options_from'])() : [])) as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @elseif ($type === 'textarea')
                            <textarea id="f-{{ $key }}" wire:model="state.{{ $key }}" rows="3" class="kt-textarea w-full"></textarea>
                        @elseif ($type === 'money')
                            <div class="kt-input w-full">
                                <span class="text-base text-muted-foreground">$</span>
                                <input id="f-{{ $key }}" type="text" inputmode="decimal"
                                       wire:model="state.{{ $key }}"
                                       @if (isset($field['min'])) min="{{ $field['min'] }}" @endif
                                       placeholder="0.00" class="amount-cell">
                            </div>
                        @elseif ($type === 'checkbox')
                            <label class="flex items-center gap-2 text-sm text-secondary-foreground">
                                <input id="f-{{ $key }}" type="checkbox" wire:model="state.{{ $key }}" class="kt-checkbox">
                                <span>{{ $field['label'] }}</span>
                            </label>
                        @else
                            <input id="f-{{ $key }}" type="{{ $type === 'money' ? 'text' : $type }}"
                                   @if ($type === 'number') inputmode="numeric" @endif
                                   @if (isset($field['min'])) min="{{ $field['min'] }}" @endif
                                   @if (isset($field['max'])) max="{{ $field['max'] }}" @endif
                                   @if (! empty($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif
                                   @if (! empty($field['autocomplete'])) autocomplete="{{ $field['autocomplete'] }}" @endif
                                   wire:model="{{ $type === 'number' ? 'state.'.$key : 'state.'.$key }}"
                                   class="kt-input w-full">
                        @endif

                        @if (! empty($field['helper']))
                            <span class="text-xs text-muted-foreground">{{ $field['helper'] }}</span>
                        @endif

                        @error('state.'.$key)
                            <span class="text-xs font-medium text-destructive">{{ $message }}</span>
                        @enderror
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="flex flex-wrap items-center gap-2.5">
        <button type="submit" class="kt-btn kt-btn-lg" wire:loading.attr="disabled">
            <i class="ki-filled ki-check"></i>
            {{ __('t.save') }}
        </button>
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('admin.dashboard') }}"
           class="kt-btn kt-btn-lg kt-btn-outline">
            {{ __('t.cancel') }}
        </a>
        <span class="text-xs text-muted-foreground">{{ __('t.unsaved_hint') }}</span>
    </div>
</form>
