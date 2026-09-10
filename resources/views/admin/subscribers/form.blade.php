@extends('layouts.admin')

@section('title', $subscriber ? __('t.edit_record') : __('t.new_record'))
@section('heading', $subscriber ? $subscriber->name : __('mdl.subscriber'))
@section('subheading', $subscriber ? __('t.edit_record') : __('t.new_record'))

@section('content')
    <div class="mx-auto max-w-3xl">
        <livewire:admin.resource-form
            :form="\App\Admin\Forms\SubscriberForm::class"
            :record="$subscriber?->id" />
    </div>
@endsection
