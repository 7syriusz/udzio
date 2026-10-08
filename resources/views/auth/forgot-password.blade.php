@extends('layouts.guest')

@section('title', __('auth.screens.forgot_password.title'))

@section('content')
<p class="mb-4">{{ __('auth.screens.forgot_password.intro') }}</p>
<form method="POST" action="{{ route('password.email') }}" novalidate>
    @csrf
    @include('auth._field', ['name' => 'email', 'label' => __('auth.fields.email'), 'type' => 'email', 'autocomplete' => 'username'])
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">{{ __('auth.screens.forgot_password.submit') }}</button>
</form>
@endsection
