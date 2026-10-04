@extends('layouts.guest')

@section('title', __('auth.screens.verify_email.title'))

@section('content')
@if (session('status') === 'verification-link-sent')
    <p class="mb-4 rounded bg-green-50 p-3 text-green-800" role="status">{{ __('auth.screens.verify_email.link_sent') }}</p>
@endif
<p class="mb-4">{{ __('auth.screens.verify_email.intro', ['email' => auth()->user()->email]) }}</p>
<form method="POST" action="{{ route('verification.send') }}">
    @csrf
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">{{ __('auth.screens.verify_email.resend') }}</button>
</form>
@endsection
