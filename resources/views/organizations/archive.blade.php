@extends('layouts.app')

@section('back')
    @include('organizations._back', ['href' => route('organizations.show', $unit->public_id), 'label' => $unit->name])
@endsection

@section('title', __('organization.screens.archive_title', ['name' => $unit->name]))

@section('content')
<x-ui.notice tone="danger">
    <p class="mb-2 font-semibold">{{ $isRoot ? __('organization.screens.archive_root_warning') : __('organization.screens.archive_unit_warning') }}</p>
    @if ($subUnits->isEmpty())
        <p class="mb-2">{{ __('organization.screens.archive_no_sub_units') }}</p>
    @else
        <p>{{ __('organization.screens.archive_sub_units', ['count' => $subUnits->count()]) }}</p>
        <ul class="mb-2 list-disc pl-5">
            @foreach ($subUnits as $sub)
                <li class="break-words">{{ $sub->name }}</li>
            @endforeach
        </ul>
    @endif
    <p>{{ __('organization.screens.archive_kept') }}</p>
</x-ui.notice>

<form method="POST" action="{{ route('organizations.archive', $unit->public_id) }}" novalidate>
    @csrf
    <x-ui.section>
        <x-ui.field name="reason" :label="__('organization.screens.archive_reason')" required />
        @if ($isRoot)
            <x-ui.field name="confirmation" :label="__('organization.screens.archive_confirmation', ['name' => $unit->name])" required />
        @endif
    </x-ui.section>
    <x-ui.actions :cancel="route('organizations.show', $unit->public_id)" :submit="__('organization.screens.archive_submit')" destructive />
</form>
@endsection
