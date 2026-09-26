@extends('layouts.guest')

@section('title', 'Potwierdź adres e-mail')

@section('content')
@if (session('status') === 'verification-link-sent')
    <p class="mb-4 rounded bg-green-50 p-3 text-green-800" role="status">Wysłaliśmy nowy link potwierdzający.</p>
@endif
<p class="mb-4">Kliknij link, który wysłaliśmy na adres <strong>{{ auth()->user()->email }}</strong>. Po potwierdzeniu połączymy konto z Twoimi danymi w Udzio.</p>
<form method="POST" action="{{ route('verification.send') }}">
    @csrf
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">Wyślij link ponownie</button>
</form>
@endsection
