@extends('layouts.app')

@section('back')
    @include('organizations._back', ['href' => route('organizations.show', $unit->public_id), 'label' => $unit->name])
@endsection

@section('title', __('organization.screens.rename_title'))

@section('content')
<form method="POST" action="{{ route('organizations.rename', $unit->public_id) }}" novalidate>
    @csrf
    @method('PUT')
    <x-ui.section>
        <x-ui.field name="name" :label="__('organization.screens.new_name')" :value="$unit->name" required autofocus />
    </x-ui.section>
    <x-ui.actions :cancel="route('organizations.show', $unit->public_id)" :submit="__('organization.screens.rename_submit')" />
</form>
@endsection
