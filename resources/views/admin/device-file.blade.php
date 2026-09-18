@extends('layouts.admin')

@section('title', $equipment->name)
@section('heading', $equipment->name)
@section('subheading', __('eq.device_sub'))

@section('content')
    <livewire:admin.pages.device-file :equipmentId="$equipment->id" :key="'device-'.$equipment->id" />
@endsection
