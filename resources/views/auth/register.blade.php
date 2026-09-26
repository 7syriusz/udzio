@extends('layouts.guest')

@section('title', 'Zakładanie konta')

@section('content')
<form method="POST" action="{{ route('register') }}">
    @csrf
    @include('auth._field', ['name' => 'given_name', 'label' => 'Imię', 'autocomplete' => 'given-name'])
    @include('auth._field', ['name' => 'family_name', 'label' => 'Nazwisko', 'autocomplete' => 'family-name'])
    @include('auth._field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'email'])
    @include('auth._field', ['name' => 'password', 'label' => 'Hasło (co najmniej 12 znaków)', 'type' => 'password', 'autocomplete' => 'new-password'])
    @include('auth._field', ['name' => 'password_confirmation', 'label' => 'Powtórz hasło', 'type' => 'password', 'autocomplete' => 'new-password'])
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">Załóż konto</button>
</form>
<p class="mt-6 text-sm">Masz już konto? <a class="text-blue-700 underline" href="{{ route('login') }}">Zaloguj się</a></p>
@endsection
