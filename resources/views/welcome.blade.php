@extends('layouts.guest')

@section('title', __('ui.welcome.title'))

@section('content')
<p class="mb-6">{{ __('ui.welcome.intro') }}</p>
@auth
    <a class="text-blue-700 underline" href="{{ route('account.show') }}">{{ __('ui.nav.account') }}</a>
@else
    <a class="text-blue-700 underline" href="{{ route('login') }}">{{ __('auth.screens.login.submit') }}</a>
    @if (Route::has('register'))
        <span class="mx-2">·</span><a class="text-blue-700 underline" href="{{ route('register') }}">{{ __('auth.screens.register.submit') }}</a>
    @endif
@endauth
@endsection
