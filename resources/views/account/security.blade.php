@extends('layouts.app')

@section('title', __('account.security.title'))

@section('content')
@php($pending = ! $user->hasConfirmedTwoFactor() && $user->two_factor_secret !== null)
<x-ui.section :title="__('account.security.two_factor')" :footer="__('account.security.two_factor_explained')">
    <div class="flex items-center justify-between gap-3 border-b border-ios-separator px-4 py-3">
        <span>{{ __('account.security.state') }}</span>
        <span class="font-medium {{ $user->hasConfirmedTwoFactor() ? 'text-ios-green' : 'text-ios-secondary' }}">
            {{ $user->hasConfirmedTwoFactor() ? __('account.security.state_enabled') : ($pending ? __('account.security.state_pending') : __('account.security.state_disabled')) }}
        </span>
    </div>

    @if ($user->hasConfirmedTwoFactor())
        @if (session('status') === 'recovery-codes-generated' || session('status') === 'two-factor-authentication-confirmed')
            <div class="border-b border-ios-separator px-4 py-3">
                <p class="mb-2">{{ __('account.security.recovery_codes') }}</p>
                <ul class="grid grid-cols-1 gap-1 font-mono sm:grid-cols-2">@foreach ($user->recoveryCodes() as $code)<li>{{ $code }}</li>@endforeach</ul>
            </div>
        @endif
        <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" class="border-b border-ios-separator px-4 py-3" novalidate>
            @csrf
            <button type="submit" class="text-ios-blue focus-visible:outline-2 focus-visible:outline-ios-blue">{{ __('account.security.regenerate_codes') }}</button>
            <p class="text-sm text-ios-secondary">{{ __('account.security.regenerate_codes_hint') }}</p>
        </form>
        <form method="POST" action="{{ route('two-factor.disable') }}" class="px-4 py-3" novalidate>
            @csrf
            @method('DELETE')
            <button type="submit" class="text-ios-red focus-visible:outline-2 focus-visible:outline-ios-red">{{ __('account.security.disable') }}</button>
        </form>
    @elseif ($pending)
        <div class="border-b border-ios-separator px-4 py-3">
            <p class="mb-2">{{ __('account.security.scan') }}</p>
            <div class="mb-1 inline-block rounded-lg bg-white p-2">{!! $user->twoFactorQrCodeSvg() !!}</div>
        </div>
        <form method="POST" action="{{ route('two-factor.confirm') }}" class="border-b border-ios-separator px-4 py-3" novalidate>
            @csrf
            <label for="code" class="mb-1 block">{{ __('account.security.scan_code') }}</label>
            <div class="flex flex-wrap gap-2">
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" aria-label="{{ __('account.security.code') }}"
                       @error('code', 'confirmTwoFactorAuthentication') aria-invalid="true" aria-describedby="code-error" @enderror
                       class="w-40 rounded-lg border border-ios-separator px-3 py-2.5 text-base tracking-widest focus:border-ios-blue focus:outline-2 focus:outline-ios-blue">
                <button type="submit" class="rounded-xl bg-ios-blue px-5 py-2.5 font-semibold text-white hover:bg-ios-blue-pressed focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ios-blue">{{ __('account.security.confirm') }}</button>
            </div>
            @error('code', 'confirmTwoFactorAuthentication') <p id="code-error" class="mt-1 text-sm text-ios-red">{{ $message }}</p> @enderror
        </form>
        <form method="POST" action="{{ route('two-factor.disable') }}" class="px-4 py-3" novalidate>
            @csrf
            @method('DELETE')
            <button type="submit" class="text-ios-blue focus-visible:outline-2 focus-visible:outline-ios-blue">{{ __('account.security.cancel_setup') }}</button>
            <p class="text-sm text-ios-secondary">{{ __('account.security.cancel_setup_hint') }}</p>
        </form>
    @else
        <form method="POST" action="{{ route('two-factor.enable') }}" class="px-4 py-3" novalidate>
            @csrf
            <button type="submit" class="rounded-xl bg-ios-blue px-5 py-2.5 font-semibold text-white hover:bg-ios-blue-pressed focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ios-blue">{{ __('account.security.enable') }}</button>
        </form>
    @endif
</x-ui.section>

<form method="POST" action="{{ route('user-password.update') }}" novalidate>
    @csrf
    @method('PUT')
    <x-ui.section :title="__('account.security.change_password')">
        @foreach ([['current_password', __('account.security.current_password'), 'current-password'], ['password', __('account.security.new_password', ['min' => config('identity.passwords.min_length')]), 'new-password'], ['password_confirmation', __('account.security.new_password_confirmation'), 'new-password']] as [$field, $label, $autocomplete])
            <div class="border-b border-ios-separator px-4 py-3 last:border-b-0">
                <label for="{{ $field }}" class="mb-1 block text-sm text-ios-secondary">{{ $label }}</label>
                <x-ui.password-input :name="$field" :autocomplete="$autocomplete" :invalid="$errors->updatePassword->has($field)" :describedby="$errors->updatePassword->has($field) ? $field.'-error' : null" />
                @error($field, 'updatePassword') <p id="{{ $field }}-error" class="mt-1 text-sm text-ios-red">{{ $message }}</p> @enderror
            </div>
        @endforeach
    </x-ui.section>
    <div class="-mt-3 mb-8 flex justify-end">
        <button type="submit" class="rounded-xl bg-ios-blue px-5 py-3 font-semibold text-white hover:bg-ios-blue-pressed focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ios-blue">{{ __('account.security.change_password_submit') }}</button>
    </div>
</form>

<form method="POST" action="{{ route('other-sessions.destroy') }}" novalidate>
    @csrf
    @method('DELETE')
    <x-ui.section :title="__('account.security.other_devices')" :footer="__('account.security.other_devices_explained')">
        <div class="px-4 py-3">
            <label for="other-password" class="mb-1 block text-sm text-ios-secondary">{{ __('account.security.password') }}</label>
            <x-ui.password-input name="password" id="other-password" :invalid="$errors->has('password')" :describedby="$errors->has('password') ? 'other-password-error' : null" />
            @error('password') <p id="other-password-error" class="mt-1 text-sm text-ios-red">{{ $message }}</p> @enderror
        </div>
    </x-ui.section>
    <div class="-mt-3 flex justify-end">
        <button type="submit" class="rounded-xl border border-ios-separator bg-ios-card px-5 py-3 font-medium text-ios-blue hover:bg-ios-bg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ios-blue">{{ __('account.security.logout_other_devices') }}</button>
    </div>
</form>
@endsection
