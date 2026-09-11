@extends('layouts.collector')

@section('title', __('portal.route_readings'))

@section('content')
    <livewire:collector.route-readings :route="$route" />
@endsection
