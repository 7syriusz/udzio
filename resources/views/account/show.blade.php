@extends('layouts.app')

@section('title', 'Moje dane')

@section('content')
@if ($person === null && $review !== null)
    <p class="rounded bg-yellow-50 p-4 text-yellow-900">
        Aby bezpiecznie połączyć konto z Twoimi danymi, potrzebna jest dodatkowa weryfikacja.
        Zgłoszenie zostało przyjęte (numer <code>{{ $review->public_id }}</code>). Skontaktujemy się z Tobą;
        do tego czasu konto działa bez danych osobowych.
    </p>
@elseif ($person === null)
    <p class="rounded bg-yellow-50 p-4 text-yellow-900">
        Konto nie jest jeszcze połączone z Twoimi danymi osobowymi. Potwierdź adres e-mail, aby je połączyć.
    </p>
@else
    <p class="mb-6 text-sm text-gray-600">Identyfikator osoby: <code>{{ $person->public_id }}</code></p>
    @include('account._person-form', ['action' => route('account.update')])
@endif
@endsection
