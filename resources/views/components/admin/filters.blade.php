@props([
    // Field schemas, same shape as a form definition (see App\Admin\FormDefinition).
    'fields',
    // Livewire property holding the values, e.g. 'filters'.
    'model' => 'filters',
    // Current values, so controls render what is bound.
    'values' => [],
    'idPrefix' => 'flt',
    // A reset button is only useful when there is something to reset to.
    'reset' => true,
])

@php
    $active = collect($values)->filter(fn ($v) => $v !== null && $v !== '' && $v !== false)->isNotEmpty();

    // The grid follows the number of filters: four columns for a four-filter report, but a
    // screen with one filter gets one narrow column instead of a control stranded in a
    // quarter-width cell with three quarters of empty card beside it.
    $count = count($fields);
@endphp

{{-- Filters live INSIDE the card header rather than floating above the content: on the page
     background the controls read as loose text, and Metronic's own lists put the search and
     filters on the card's edge. --}}
{{-- The width cap belongs on the CARD, not on its content box: capping the content alone
     leaves a full-width white card with the controls huddled in one corner. Measured, not
     guessed — a single-filter bar was 1260px of card around a 408px select. --}}
<div @class([
    'kt-card',
    'max-w-md' => $count === 1,
    'max-w-2xl' => $count === 2,
])>
    <div @class([
        'kt-card-content grid gap-3 py-4',
        'sm:grid-cols-2' => $count >= 2,
        'lg:grid-cols-3' => $count === 3,
        'lg:grid-cols-4' => $count >= 4,
    ])>
        @foreach ($fields as $field)
            <x-admin.field :field="$field" :model="$model" :values="$values" :id-prefix="$idPrefix" compact />
        @endforeach

        @if ($reset)
            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="button" wire:click="resetFilters"
                        @disabled(! $active)
                        class="kt-btn kt-btn-sm kt-btn-outline disabled:opacity-40">
                    <i class="ki-filled ki-arrows-circle"></i>
                    {{ __('t.clear_filters') }}
                </button>
            </div>
        @endif
    </div>
</div>
