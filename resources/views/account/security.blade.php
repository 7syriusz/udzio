@extends('layouts.app')

@section('title', 'Bezpieczeństwo konta')

@section('content')
<section class="mb-10">
    <h2 class="mb-2 text-lg font-semibold">Uwierzytelnianie dwuskładnikowe</h2>
    @if ($user->hasConfirmedTwoFactor())
        <p class="mb-2">Włączone.</p>
        @if (session('status') === 'recovery-codes-generated' || session('status') === 'two-factor-authentication-confirmed')
            <p class="mb-1">Kody odzyskiwania (zapisz je w bezpiecznym miejscu):</p>
            <ul class="mb-4 font-mono">@foreach ($user->recoveryCodes() as $code)<li>{{ $code }}</li>@endforeach</ul>
        @endif
        <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" class="mb-2">@csrf
            <button class="underline">Wygeneruj nowe kody odzyskiwania</button>
        </form>
        <form method="POST" action="{{ route('two-factor.disable') }}">@csrf @method('DELETE')
            <button class="text-red-700 underline">Wyłącz</button>
        </form>
    @elseif ($user->two_factor_secret !== null)
        <p class="mb-2">Zeskanuj kod w aplikacji uwierzytelniającej i wpisz wygenerowany kod.</p>
        <div class="mb-4">{!! $user->twoFactorQrCodeSvg() !!}</div>
        <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex gap-2">@csrf
            <label class="sr-only" for="code">Kod</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" class="rounded border px-3 py-2">
            <button class="rounded bg-blue-700 px-4 py-2 text-white">Potwierdź</button>
        </form>
        @error('code', 'confirmTwoFactorAuthentication') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
    @else
        <p class="mb-2">Wyłączone.</p>
        <form method="POST" action="{{ route('two-factor.enable') }}">@csrf
            <button class="rounded bg-blue-700 px-4 py-2 text-white">Włącz</button>
        </form>
    @endif
</section>

<section class="mb-10 max-w-md">
    <h2 class="mb-2 text-lg font-semibold">Zmiana hasła</h2>
    <form method="POST" action="{{ route('user-password.update') }}">
        @csrf
        @method('PUT')
        @foreach ([['current_password', 'Obecne hasło', 'current-password'], ['password', 'Nowe hasło (co najmniej 12 znaków)', 'new-password'], ['password_confirmation', 'Powtórz nowe hasło', 'new-password']] as [$field, $label, $autocomplete])
            <div class="mb-3">
                <label for="{{ $field }}" class="block text-sm">{{ $label }}</label>
                <input id="{{ $field }}" name="{{ $field }}" type="password" autocomplete="{{ $autocomplete }}" required class="w-full rounded border px-3 py-2">
                @error($field, 'updatePassword') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <button class="rounded bg-blue-700 px-4 py-2 text-white">Zmień hasło</button>
    </form>
</section>

<section class="max-w-md">
    <h2 class="mb-2 text-lg font-semibold">Pozostałe urządzenia</h2>
    <form method="POST" action="{{ route('other-sessions.destroy') }}">
        @csrf
        @method('DELETE')
        <label for="other-password" class="block text-sm">Hasło</label>
        <input id="other-password" name="password" type="password" autocomplete="current-password" required class="mb-3 w-full rounded border px-3 py-2">
        @error('password') <p class="mb-2 text-sm text-red-700">{{ $message }}</p> @enderror
        <button class="rounded border px-4 py-2">Wyloguj pozostałe urządzenia</button>
    </form>
</section>
@endsection
