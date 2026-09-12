@extends('layouts.admin')

@section('title', __('mdl.subscribers'))
@section('heading', __('mdl.subscribers'))
@section('subheading', __('t.subscribers_sub'))

@section('content')
    {{-- This screen is hand-routed (a subscriber's profile has no equivalent among the registry
         entries), so it has to ask for the rails the registry-driven lists get for free: the
         "add new" button lives beside the list rather than inside it. --}}
    <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
        <x-admin.create-button route="admin.subscribers.create" />
    </div>

    <livewire:admin.data-table :table="\App\Admin\Tables\SubscribersTable::class" />
@endsection
