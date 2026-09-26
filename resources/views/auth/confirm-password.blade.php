@extends('layouts.guest')

@section('title', 'Potwierdź hasło')

@section('content')
<p class="mb-4">To ustawienie bezpieczeństwa. Potwierdź je hasłem.</p>
<form method="POST" action="{{ route('password.confirm.store') }}">
    @csrf
    @include('auth._field', ['name' => 'password', 'label' => 'Hasło', 'type' => 'password', 'autocomplete' => 'current-password'])
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">Potwierdź</button>
</form>
@endsection
