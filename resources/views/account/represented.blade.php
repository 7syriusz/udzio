@extends('layouts.app')

@section('title', 'Osoby reprezentowane')

@section('content')
<ul>
@forelse ($representations as $representation)
    <li class="mb-2">
        <a class="text-blue-700 underline" href="{{ route('account.represented.show', $representation->represented) }}">{{ $representation->represented->fullName() }}</a>
        <span class="text-sm text-gray-600">(podstawa: {{ $representation->method->label() }}; zakres: {{ implode(', ', $representation->scopes) }})</span>
    </li>
@empty
    <li>Nie reprezentujesz żadnej osoby.</li>
@endforelse
</ul>
@endsection
