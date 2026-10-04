@extends('layouts.guest')

@section('title', __('auth.screens.reset_password.title'))

@section('content')
<form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $request->route('token') }}">
    @include('auth._field', ['name' => 'email', 'label' => __('auth.fields.email'), 'type' => 'email', 'autocomplete' => 'username'])
    @include('auth._field', ['name' => 'password', 'label' => __('auth.fields.new_password'), 'type' => 'password', 'autocomplete' => 'new-password'])
    @include('auth._field', ['name' => 'password_confirmation', 'label' => __('auth.fields.password_confirmation'), 'type' => 'password', 'autocomplete' => 'new-password'])
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">{{ __('auth.screens.reset_password.submit') }}</button>
</form>
@endsection
