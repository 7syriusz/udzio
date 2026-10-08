@extends('layouts.app')

@section('back')
    @include('organizations._back', ['href' => route('organizations.index'), 'label' => __('organization.screens.title')])
@endsection

@section('title', __('organization.screens.found_title'))

@section('content')
<form method="POST" action="{{ route('organizations.store') }}" novalidate>
    @csrf
    <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
    <x-ui.section :footer="__('organization.screens.found_intro').' '.__('organization.screens.found_mfa_note')">
        <x-ui.field name="name" :label="__('organization.screens.name')" required autofocus />
    </x-ui.section>
    <x-ui.actions :cancel="route('organizations.index')" :submit="__('organization.screens.found_submit')" />
</form>
@endsection
