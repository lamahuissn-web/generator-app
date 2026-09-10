@extends('layouts.admin')

@section('title', __('mdl.subscribers'))
@section('heading', __('mdl.subscribers'))
@section('subheading', __('t.subscribers_sub'))

@section('content')
    <livewire:admin.data-table :table="\App\Admin\Tables\SubscribersTable::class" />
@endsection
