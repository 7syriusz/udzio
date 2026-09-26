@extends('layouts.guest')

@section('title', 'Nowe hasło')

@section('content')
<form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $request->route('token') }}">
    @include('auth._field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'username'])
    @include('auth._field', ['name' => 'password', 'label' => 'Nowe hasło (co najmniej 12 znaków)', 'type' => 'password', 'autocomplete' => 'new-password'])
    @include('auth._field', ['name' => 'password_confirmation', 'label' => 'Powtórz hasło', 'type' => 'password', 'autocomplete' => 'new-password'])
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">Ustaw hasło</button>
</form>
@endsection
