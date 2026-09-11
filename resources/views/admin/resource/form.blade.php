@extends('layouts.admin')

@section('title', $record ? $resource['singular'] : __('t.new_record'))
@section('heading', $record ? $resource['singular'] : $resource['title'])
@section('subheading', $record ? __('t.edit_record') : __('t.new_record'))

@section('content')
    <div class="mx-auto max-w-3xl">
        <livewire:admin.resource-form
            :form="$resource['form']"
            :record="$record?->getKey()" />
    </div>
@endsection
