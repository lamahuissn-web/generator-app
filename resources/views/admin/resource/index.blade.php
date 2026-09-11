@extends('layouts.admin')

@section('title', $resource['title'])
@section('heading', $resource['title'])
@section('subheading', $resource['description'] ?? '')

@section('content')
    {{-- The create button belongs to the screen, not to the table definition: the same table
         component renders inside a subscriber's profile tabs, where "add new" is meaningless. --}}
    @if (! empty($resource['form']) && \Illuminate\Support\Facades\Route::has('admin.'.$resourceKey.'.create'))
        <div class="mb-4 flex justify-end">
            <a href="{{ route('admin.'.$resourceKey.'.create') }}" class="kt-btn kt-btn-sm">
                <i class="ki-filled ki-plus"></i>
                {{ __('t.new_record') }}
            </a>
        </div>
    @endif

    <livewire:admin.data-table :table="$resource['table']" :key="$resourceKey" />
@endsection
