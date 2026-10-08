@extends('layouts.app')

@php($dates = app(\App\Domain\Platform\Localization\DateDisplay::class))

@if ($back)
    @section('back')
        @include('organizations._back', ['href' => route('organizations.archived', $back->public_id), 'label' => __('organization.screens.archived_title')])
    @endsection
@endif

@section('title', __('organization.screens.history_title', ['name' => $unit->name]))

@section('content')
<x-ui.section>
    <dl>
        <div class="border-b border-ios-separator px-4 py-3"><dt class="text-sm text-ios-secondary">{{ __('organization.screens.history_archived_on') }}</dt><dd>{{ $dates->dateTime($unit->archived_at) }}</dd></div>
        <div class="px-4 py-3"><dt class="text-sm text-ios-secondary">{{ __('organization.screens.history_place') }}</dt><dd class="break-words">{{ $place !== '' ? $place : __('organization.screens.history_root') }}</dd></div>
    </dl>
</x-ui.section>

@if ($periods->isNotEmpty())
    <x-ui.section :title="__('organization.screens.history_periods')">
        <ul>
            @foreach ($periods as $period)
                <li class="border-b border-ios-separator px-4 py-3 last:border-b-0">
                    <span class="block break-words">{{ $period->parent->name }}</span>
                    <span class="text-sm text-ios-secondary">{{ $period->valid_to ? __('organization.screens.history_period', ['from' => $dates->dateTime($period->valid_from), 'to' => $dates->dateTime($period->valid_to)]) : __('organization.screens.history_since', ['from' => $dates->dateTime($period->valid_from)]) }}</span>
                </li>
            @endforeach
        </ul>
    </x-ui.section>
@endif
@endsection
