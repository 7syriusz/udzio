@extends('layouts.guest')

@section('title', 'Kod weryfikacyjny')

@section('content')
<p class="mb-4">Podaj kod z aplikacji uwierzytelniającej albo jeden z kodów odzyskiwania.</p>
<form method="POST" action="{{ route('two-factor.login.store') }}">
    @csrf
    @include('auth._field', ['name' => 'code', 'label' => 'Kod z aplikacji', 'autocomplete' => 'one-time-code', 'required' => false])
    @include('auth._field', ['name' => 'recovery_code', 'label' => 'albo kod odzyskiwania', 'required' => false])
    <button type="submit" class="w-full rounded bg-blue-700 px-4 py-2 font-medium text-white">Potwierdź</button>
</form>
@endsection
