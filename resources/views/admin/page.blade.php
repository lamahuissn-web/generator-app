@extends('layouts.admin')

{{-- One wrapper for every registry-driven screen: the component names its own heading and
     the sidebar's own metadata supplies the title, so a new screen adds no Blade file. --}}
@section('title', $heading)
@section('heading', $heading)
@section('subheading', $subheading)

@section('content')
    <livewire:dynamic-component :is="$component" />
@endsection
