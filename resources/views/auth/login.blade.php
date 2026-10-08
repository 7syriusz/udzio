@extends('layouts.guest')

@section('title', __('auth.screens.login.title'))

@section('content')
<form method="POST" action="{{ route('login') }}" novalidate>
    @csrf
    @include('auth._field', ['name' => 'email', 'label' => __('auth.fields.email'), 'type' => 'email', 'autocomplete' => 'username'])
    @include('auth._field', ['name' => 'password', 'label' => __('auth.fields.password'), 'type' => 'password', 'autocomplete' => 'current-password'])
    <label class="mb-4 flex items-center gap-2 text-sm">
        <input type="checkbox" name="remember" value="1"> {{ __('auth.screens.login.remember') }}
    </label>
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">{{ __('auth.screens.login.submit') }}</button>
</form>
@if (Route::has('password.request'))
    <p class="mt-4 text-sm"><a class="text-blue-700 underline" href="{{ route('password.request') }}">{{ __('auth.screens.login.forgot') }}</a></p>
@endif
@if (Route::has('register'))
    <p class="mt-6 text-sm">{{ __('auth.screens.login.no_account') }} <a class="text-blue-700 underline" href="{{ route('register') }}">{{ __('auth.screens.register.submit') }}</a></p>
@endif
@endsection
