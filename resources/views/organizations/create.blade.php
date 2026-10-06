@extends('layouts.app')

@section('title', __('organization.screens.found_title'))

@section('content')
<p class="mb-2">{{ __('organization.screens.found_intro') }}</p>
<p class="mb-6 text-sm text-gray-700">{{ __('organization.screens.found_mfa_note') }}</p>
<form method="POST" action="{{ route('organizations.store') }}" class="max-w-xl">
    @csrf
    <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
    @include('auth._field', ['name' => 'name', 'label' => __('organization.screens.name')])
    <button type="submit" class="rounded bg-blue-700 px-4 py-2 text-white">{{ __('organization.screens.found') }}</button>
</form>
@endsection
