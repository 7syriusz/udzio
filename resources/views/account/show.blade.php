@extends('layouts.app')

@section('title', __('account.person.title'))

@section('content')
@if ($person === null && $review !== null)
    <p class="rounded bg-yellow-50 p-4 text-yellow-900">
        {{ __('account.person.review_pending', ['number' => $review->public_id]) }}
    </p>
@elseif ($person === null)
    <p class="rounded bg-yellow-50 p-4 text-yellow-900">
        {{ __('account.person.not_linked') }}
    </p>
@else
    @include('account._person-form', ['action' => route('account.update')])
@endif
@endsection
