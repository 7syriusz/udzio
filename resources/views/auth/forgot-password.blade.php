@extends('layouts.guest')

@section('title', 'Nie pamiętam hasła')

@section('content')
<p class="mb-4">Podaj adres e-mail konta. Jeśli konto istnieje, wyślemy link do ustawienia nowego hasła.</p>
<form method="POST" action="{{ route('password.email') }}">
    @csrf
    @include('auth._field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'username'])
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">Wyślij link</button>
</form>
@endsection
