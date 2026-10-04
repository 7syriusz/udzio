@extends('layouts.app')

@section('title', $person->fullName())

@section('content')
@if ($canUpdate)
    @include('account._person-form', ['action' => route('account.represented.update', $person)])
@else
    <dl>
        <dt class="font-medium">{{ __('account.person.full_name') }}</dt><dd class="mb-2">{{ $person->fullName() }}</dd>
        <dt class="font-medium">{{ __('account.person.birth_date') }}</dt><dd>{{ $person->birth_date?->format('Y-m-d') ?? __('ui.empty_value') }}</dd>
    </dl>
@endif
@endsection
