@props([
    // The field schema (see App\Admin\FormDefinition): key, label, type, span, rules, …
    'field',
    // Base name of the Livewire property, e.g. 'state' or 'filters'.
    'model',
    // Current values, so the image and repeater branches can read what is bound.
    'values' => [],
    // Prefix for the DOM id ("f-name", "flt-period"), so every label points at its control.
    'idPrefix' => 'f',
    'rows' => 3,
    // Laid out as a full row inside a 2-column grid (the form default) …
    'span' => null,
    // … or as a compact cell in a filter bar.
    'compact' => false,
    // Reserve the message's line even when there is no message. Used inside dialogs: the dialog's
    // footer sits below this field, so a message that appears on validation pushes the confirm
    // button down — out from under the operator's cursor, mid-press.
    'reserveError' => false,
])

@php
    $key = $field['key'];
    $type = $field['type'] ?? 'text';
    $path = $model.'.'.$key;
    $value = $values[$key] ?? null;
    // A conditional field is required only in the state that shows it, so the marker has to
    // ask the same closure the rules do — otherwise the form asks for a field without saying so.
    $required = in_array('required', $field['rules'] ?? [], true)
        || (isset($field['required_when']) && ($field['required_when'])($values));
    // options_from receives the state so a picker can be filtered by what the operator typed.
    $options = $field['options'] ?? (isset($field['options_from']) ? ($field['options_from'])($values) : []);
    // Helper text is sometimes derived from the state ("continues from 1,270"): computed here
    // so the field does not have to re-declare which part of the state it depends on.
    $helper = isset($field['helper_from']) ? ($field['helper_from'])($values) : ($field['helper'] ?? null);
    $full = ($span ?? $field['span'] ?? 1) === 'full';

    // A checkbox draws its own box beside a caption, so it does not get the stacked label
    // treatment — the caption IS the label.
    $stacked = $type !== 'checkbox';
@endphp

<div @class([
    'flex flex-col gap-1.5',
    'sm:col-span-2' => $full && ! $compact,
    'col-span-2' => $full && $compact,
])>
    @if ($stacked)
        <label for="{{ $idPrefix }}-{{ $key }}" class="kt-form-label font-normal text-mono">
            {{ $field['label'] }}
            @if ($required)
                <span class="text-destructive" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    @if ($type === 'date')
        <input id="{{ $idPrefix }}-{{ $key }}" type="date" wire:model="{{ $path }}" class="kt-input w-full">

    @elseif ($type === 'password')
        <div class="kt-input w-full">
            <i class="ki-filled ki-lock-2"></i>
            <input id="{{ $idPrefix }}-{{ $key }}" type="password" wire:model="{{ $path }}" autocomplete="new-password">
        </div>

    @elseif ($type === 'image')
        <div class="flex items-center gap-3">
            @php
                // Two states in one field: a newly chosen file (temporary upload) or the path
                // already stored on the record.
                $stored = is_string($value) && $value !== ''
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($value)
                    : null;
            @endphp

            @if ($value && ! is_string($value))
                <img src="{{ $value->temporaryUrl() }}" alt="" class="size-16 rounded-lg border border-border object-cover">
            @elseif ($stored)
                <img src="{{ $stored }}" alt="" class="size-16 rounded-lg border border-border object-cover">
            @else
                <span class="tile tile-muted size-16">
                    <i class="ki-filled ki-picture text-xl"></i>
                </span>
            @endif

            <input id="{{ $idPrefix }}-{{ $key }}" type="file" accept="image/*" wire:model="{{ $path }}"
                   class="kt-input w-full p-1.5 file:me-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5">
        </div>

    @elseif ($type === 'month')
        {{-- A month is chosen on a calendar rather than in a list of eighteen cycles: see
             App\Livewire\Admin\MonthPicker. It keeps its own year and open state, and hands the
             chosen 'YYYY-MM' straight back to this form's property. --}}
        {{-- The listener sits on the host's own element: inside the picker's root, `$wire` is the
             picker's, and the month would be written into a component that has no such property. The
             event bubbles out of the picker to here. --}}
        <div x-on:month-picked="$wire.set('{{ $path }}', $event.detail.month)" class="contents">
            <livewire:admin.month-picker
                :value="$value"
                :placeholder="$field['placeholder'] ?? __('t.pick_month')"
                :key="'month-'.$idPrefix.'-'.$key" />
        </div>

    @elseif ($type === 'select')
        {{-- .live: the screen's figures are derived from this value, so a change has to
             re-render rather than wait for a submit the screen may not even have. --}}
        <select id="{{ $idPrefix }}-{{ $key }}" wire:model.live="{{ $path }}" class="kt-select w-full">
            <option value="">{{ $field['placeholder'] ?? __('t.select_placeholder') }}</option>
            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) $optionValue === (string) $value)>{{ $optionLabel }}</option>
            @endforeach
        </select>

    @elseif ($type === 'money')
        <div class="kt-input w-full">
            <span class="text-base text-muted-foreground">$</span>
            <input id="{{ $idPrefix }}-{{ $key }}" type="text" inputmode="decimal" wire:model="{{ $path }}"
                   @if (isset($field['min'])) min="{{ $field['min'] }}" @endif
                   placeholder="0.00" class="amount-cell">
        </div>

    @elseif ($type === 'textarea')
        <textarea id="{{ $idPrefix }}-{{ $key }}" wire:model="{{ $path }}" rows="{{ $compact ? 2 : $rows }}"
                  class="kt-textarea w-full"></textarea>

    @elseif ($type === 'search')
        {{-- Drives the picker next to it, so it renders live: waiting for a submit would mean
             typing a name and seeing nothing happen. --}}
        <div class="kt-input w-full">
            <i class="ki-filled ki-magnifier"></i>
            <input id="{{ $idPrefix }}-{{ $key }}" type="search" wire:model.live.debounce.300ms="{{ $path }}"
                   @if (! empty($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif>
        </div>

    @elseif ($type === 'checkbox')
        <label class="flex items-center gap-2 text-sm text-secondary-foreground">
            <input id="{{ $idPrefix }}-{{ $key }}" type="checkbox" wire:model="{{ $path }}" class="kt-checkbox">
            <span>{{ $field['label'] }}</span>
        </label>

    @elseif ($type === 'repeater')
        {{-- Itemized charges: description / quantity / unit price, with a running line total.
             Rows are plain Livewire state, so no nested component and no Alpine bookkeeping.
             The host component owns addItem()/removeItem(). --}}
        <div class="grid gap-2">
            @foreach ($value ?? [] as $index => $line)
                <div class="grid grid-cols-12 gap-2">
                    <input type="text" wire:model="{{ $path }}.{{ $index }}.description"
                           list="item-presets" placeholder="{{ __('t.item_desc') }}"
                           class="kt-input kt-input-sm col-span-6">
                    <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="{{ $path }}.{{ $index }}.quantity"
                           placeholder="{{ __('t.qty') }}" class="kt-input kt-input-sm col-span-2 text-center">
                    <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="{{ $path }}.{{ $index }}.unit_price"
                           placeholder="{{ __('t.unit_price') }}" class="kt-input kt-input-sm col-span-2 text-center">
                    <div class="col-span-2 flex items-center justify-between gap-1">
                        <span class="amount-cell text-xs font-medium text-mono">
                            {{ money(round((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0), 2)) }}
                        </span>
                        <button type="button" wire:click="removeItem('{{ $key }}', {{ $index }})"
                                class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost kt-btn-destructive"
                                title="{{ __('t.delete') }}">
                            <i class="ki-filled ki-cross"></i>
                        </button>
                    </div>
                </div>
            @endforeach

            <div class="flex items-center justify-between gap-2">
                <button type="button" wire:click="addItem('{{ $key }}')" class="kt-btn kt-btn-sm kt-btn-outline">
                    <i class="ki-filled ki-plus"></i>
                    {{ __('a.add_line') }}
                </button>
                {{-- Labelled: an unlabelled figure next to the add button is
                     indistinguishable from the per-line totals above it. --}}
                <span class="flex items-baseline gap-1.5 text-sm">
                    <span class="text-muted-foreground">{{ __('t.total') }}</span>
                    <span class="amount-cell font-semibold text-mono">
                        {{ money(collect($value ?? [])->sum(fn ($l) => round((float) ($l['quantity'] ?? 0) * (float) ($l['unit_price'] ?? 0), 2))) }}
                    </span>
                </span>
            </div>
        </div>

    @else
        {{-- A number input's `step` is NOT cosmetic and its default is a trap: HTML gives it step=1 with the
             step BASE at `min`, so `min="0.01"` (the litres box) makes the only valid values 0.01, 1.01, 2.01 …
             and the browser refuses 200 — the office's own daily figure — in its own language, before any
             request is sent, so nothing in PHP ever sees it. `step="any"` removes that constraint and leaves
             `min`/`max` doing their job; a definition that wants whole steps still declares its own. --}}
        <input id="{{ $idPrefix }}-{{ $key }}" type="{{ $type }}"
               @if ($type === 'number') inputmode="decimal" step="{{ $field['step'] ?? 'any' }}" @endif
               @if (isset($field['min'])) min="{{ $field['min'] }}" @endif
               @if (isset($field['max'])) max="{{ $field['max'] }}" @endif
               @if (! empty($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif
               @if (! empty($field['autocomplete'])) autocomplete="{{ $field['autocomplete'] }}" @endif
               wire:model="{{ $path }}" class="kt-input w-full">
    @endif

    @if (! empty($helper))
        <span class="text-xs text-muted-foreground">{{ $helper }}</span>
    @endif

    @if ($reserveError)
        {{-- min-h-4 reserves the message's own 16px line box, so the field's height is the same
             whether or not a message is showing and nothing below it can move. --}}
        <span class="min-h-4 text-xs font-medium text-destructive" role="alert">{{ $errors->first($path) }}</span>
    @else
        @error($path)
            <span class="text-xs font-medium text-destructive">{{ $message }}</span>
        @enderror
    @endif
</div>
