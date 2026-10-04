@extends('layouts.guest')

@section('title', __('auth.screens.two_factor_challenge.title'))

@section('content')
<p class="mb-4">{{ __('auth.screens.two_factor_challenge.intro') }}</p>
<form method="POST" action="{{ route('two-factor.login.store') }}">
    @csrf
    @include('auth._field', ['name' => 'code', 'label' => __('auth.screens.two_factor_challenge.code'), 'autocomplete' => 'one-time-code', 'required' => false])
    @include('auth._field', ['name' => 'recovery_code', 'label' => __('auth.screens.two_factor_challenge.recovery_code'), 'required' => false])
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">{{ __('auth.screens.two_factor_challenge.submit') }}</button>
</form>
@endsection
