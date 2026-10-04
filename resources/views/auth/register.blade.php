@extends('layouts.guest')

@section('title', __('auth.screens.register.title'))

@section('content')
<form method="POST" action="{{ route('register') }}">
    @csrf
    @include('auth._field', ['name' => 'given_name', 'label' => __('auth.fields.given_name'), 'autocomplete' => 'given-name'])
    @include('auth._field', ['name' => 'family_name', 'label' => __('auth.fields.family_name'), 'autocomplete' => 'family-name'])
    @include('auth._field', ['name' => 'email', 'label' => __('auth.fields.email'), 'type' => 'email', 'autocomplete' => 'email'])
    @include('auth._field', ['name' => 'password', 'label' => __('auth.fields.password_with_rule'), 'type' => 'password', 'autocomplete' => 'new-password'])
    @include('auth._field', ['name' => 'password_confirmation', 'label' => __('auth.fields.password_confirmation'), 'type' => 'password', 'autocomplete' => 'new-password'])
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">{{ __('auth.screens.register.submit') }}</button>
</form>
<p class="mt-6 text-sm">{{ __('auth.screens.register.has_account') }} <a class="text-blue-700 underline" href="{{ route('login') }}">{{ __('auth.screens.register.login') }}</a></p>
@endsection
