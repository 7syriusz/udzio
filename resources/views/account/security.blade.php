@extends('layouts.app')

@section('title', __('account.security.title'))

@section('content')
<section class="mb-10">
    <h2 class="mb-2 text-lg font-semibold">{{ __('account.security.two_factor') }}</h2>
    @if ($user->hasConfirmedTwoFactor())
        <p class="mb-2">{{ __('account.security.enabled') }}</p>
        @if (session('status') === 'recovery-codes-generated' || session('status') === 'two-factor-authentication-confirmed')
            <p class="mb-1">{{ __('account.security.recovery_codes') }}</p>
            <ul class="mb-4 font-mono">@foreach ($user->recoveryCodes() as $code)<li>{{ $code }}</li>@endforeach</ul>
        @endif
        <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" class="mb-2">@csrf
            <button class="underline">{{ __('account.security.regenerate_codes') }}</button>
        </form>
        <form method="POST" action="{{ route('two-factor.disable') }}">@csrf @method('DELETE')
            <button class="text-red-700 underline">{{ __('account.security.disable') }}</button>
        </form>
    @elseif ($user->two_factor_secret !== null)
        <p class="mb-2">{{ __('account.security.scan') }}</p>
        <div class="mb-4">{!! $user->twoFactorQrCodeSvg() !!}</div>
        <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex gap-2">@csrf
            <label class="sr-only" for="code">{{ __('account.security.code') }}</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" class="rounded border px-3 py-2">
            <button class="rounded bg-blue-700 px-4 py-2 text-white">{{ __('ui.actions.confirm') }}</button>
        </form>
        @error('code', 'confirmTwoFactorAuthentication') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
    @else
        <p class="mb-2">{{ __('account.security.disabled') }}</p>
        <form method="POST" action="{{ route('two-factor.enable') }}">@csrf
            <button class="rounded bg-blue-700 px-4 py-2 text-white">{{ __('account.security.enable') }}</button>
        </form>
    @endif
</section>

<section class="mb-10 max-w-md">
    <h2 class="mb-2 text-lg font-semibold">{{ __('account.security.change_password') }}</h2>
    <form method="POST" action="{{ route('user-password.update') }}">
        @csrf
        @method('PUT')
        @foreach ([['current_password', __('account.security.current_password'), 'current-password'], ['password', __('account.security.new_password', ['min' => config('identity.passwords.min_length')]), 'new-password'], ['password_confirmation', __('account.security.new_password_confirmation'), 'new-password']] as [$field, $label, $autocomplete])
            <div class="mb-3">
                <label for="{{ $field }}" class="block text-sm">{{ $label }}</label>
                <input id="{{ $field }}" name="{{ $field }}" type="password" autocomplete="{{ $autocomplete }}" required class="w-full rounded border px-3 py-2">
                @error($field, 'updatePassword') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <button class="rounded bg-blue-700 px-4 py-2 text-white">{{ __('account.security.change_password_submit') }}</button>
    </form>
</section>

<section class="max-w-md">
    <h2 class="mb-2 text-lg font-semibold">{{ __('account.security.other_devices') }}</h2>
    <form method="POST" action="{{ route('other-sessions.destroy') }}">
        @csrf
        @method('DELETE')
        <label for="other-password" class="block text-sm">{{ __('account.security.password') }}</label>
        <input id="other-password" name="password" type="password" autocomplete="current-password" required class="mb-3 w-full rounded border px-3 py-2">
        @error('password') <p class="mb-2 text-sm text-red-700">{{ $message }}</p> @enderror
        <button class="rounded border px-4 py-2">{{ __('account.security.logout_other_devices') }}</button>
    </form>
</section>
@endsection
