@extends('layouts.collector')

@section('title', $subscriber->name)

@section('content')
    <livewire:collector.subscriber-show :subscriber="$subscriber" />
@endsection
