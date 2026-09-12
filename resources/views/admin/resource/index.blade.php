@extends('layouts.admin')

@section('title', $resource['title'])
@section('heading', $resource['title'])
@section('subheading', $resource['description'] ?? '')

@section('content')
    {{-- Screens with their own chrome above the list describe it in the registry (the readings
         screen links to the two questions a list of existing readings cannot answer), so this
         generic wrapper stays generic. --}}
    @if (! empty($resource['header']))
        @include($resource['header'])
    @endif

    {{-- The screen's own buttons and the generic "add new" share one rail at the end of the row: two
         page-level actions floating on opposite sides read as two unrelated toolbars. --}}
    @if (! empty($resource['actions_view']) || \Illuminate\Support\Facades\Route::has('admin.'.$resourceKey.'.create'))
        <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
            @if (! empty($resource['actions_view']))
                @include($resource['actions_view'])
            @endif

            {{-- The create button belongs to the screen, not to the table definition: the same table
                 component renders inside a subscriber's profile tabs, where "add new" is meaningless.
                 It renders nothing for a read-only screen, since those have no create route. --}}
            <x-admin.create-button :route="'admin.'.$resourceKey.'.create'" />
        </div>
    @endif

    <livewire:admin.data-table :table="$resource['table']" :key="$resourceKey" />
@endsection
