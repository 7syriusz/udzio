@extends('layouts.guest')

@section('title', 'Logowanie')

@section('content')
<form method="POST" action="{{ route('login') }}">
    @csrf
    @include('auth._field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'username'])
    @include('auth._field', ['name' => 'password', 'label' => 'Hasło', 'type' => 'password', 'autocomplete' => 'current-password'])
    <label class="mb-4 flex items-center gap-2 text-sm">
        <input type="checkbox" name="remember" value="1"> Zapamiętaj mnie
    </label>
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">Zaloguj</button>
</form>
@if (Route::has('register'))
    <p class="mt-6 text-sm">Nie masz konta? <a class="text-blue-700 underline" href="{{ route('register') }}">Załóż konto</a></p>
@endif
@endsection
