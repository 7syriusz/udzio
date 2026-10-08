@extends('layouts.app')

@section('back')
    @include('organizations._back', ['href' => route('organizations.show', $unit->public_id), 'label' => $unit->name])
@endsection

@section('title', __('organization.screens.create_unit_title'))

@section('content')
<form method="POST" action="{{ route('organizations.units.store', $unit->public_id) }}" novalidate>
    @csrf
    <x-ui.section :footer="__('organization.screens.create_unit_in', ['path' => $unit->name])">
        <x-ui.field name="name" :label="__('organization.screens.unit_name')" required autofocus />
    </x-ui.section>
    <x-ui.actions :cancel="route('organizations.show', $unit->public_id)" :submit="__('organization.screens.create_submit')" />
</form>
@endsection
