@extends('layouts.app')

@section('title', $person->fullName())

@section('content')
@if ($canUpdate)
    @include('account._person-form', ['action' => route('account.represented.update', $person)])
@else
    <dl>
        <dt class="font-medium">Imię i nazwisko</dt><dd class="mb-2">{{ $person->fullName() }}</dd>
        <dt class="font-medium">Data urodzenia</dt><dd>{{ $person->birth_date?->format('Y-m-d') ?? '—' }}</dd>
    </dl>
@endif
@endsection
