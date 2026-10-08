@extends('layouts.app')

@section('back')
    @include('organizations._back', ['href' => route('organizations.show', $unit->public_id), 'label' => $unit->name])
@endsection

@section('title', __('organization.screens.archived_title'))

@section('content')
<x-ui.section :footer="__('organization.screens.archived_in', ['name' => $unit->name])">
    @forelse ($archived as $row)
        <x-ui.row-link :href="route('organizations.history', $row['unit']->public_id)" :label="$row['unit']->name" :detail="__('organization.screens.archived_on', ['date' => app(\App\Domain\Platform\Localization\DateDisplay::class)->date($row['unit']->archived_at)])" />
    @empty
        <p class="px-4 py-3 text-ios-secondary">{{ __('organization.screens.archived_empty') }}</p>
    @endforelse
</x-ui.section>
@endsection
