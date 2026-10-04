@extends('layouts.guest')

@section('title', __('auth.screens.confirm_password.title'))

@section('content')
<p class="mb-4">{{ __('auth.screens.confirm_password.intro') }}</p>
<form method="POST" action="{{ route('password.confirm.store') }}">
    @csrf
    @include('auth._field', ['name' => 'password', 'label' => __('auth.fields.password'), 'type' => 'password', 'autocomplete' => 'current-password'])
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">{{ __('auth.screens.confirm_password.submit') }}</button>
</form>
@endsection
