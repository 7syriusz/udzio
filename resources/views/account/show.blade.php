@extends('layouts.app')

@section('title', 'Moje dane')

@section('content')
@if ($person === null)
    <p class="rounded bg-yellow-50 p-4 text-yellow-900">
        Konto nie jest jeszcze połączone z Twoimi danymi osobowymi. Jeśli adres e-mail został już potwierdzony,
        skontaktuj się z organizatorem — połączymy konto ręcznie.
    </p>
@else
    <p class="mb-6 text-sm text-gray-600">Identyfikator osoby: <code>{{ $person->public_id }}</code></p>
    @include('account._person-form', ['action' => route('account.update')])
@endif
@endsection
