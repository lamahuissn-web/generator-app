@extends('layouts.collector')

@section('title', __('portal.route_readings'))

@section('content')
    <livewire:route-reading-sheet :route="$route" />
@endsection
