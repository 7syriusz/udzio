@extends('layouts.app')

@section('back')
    @if ($path->isNotEmpty())
        @include('organizations._back', ['href' => route('organizations.show', $path->last()->public_id), 'label' => $path->last()->name])
    @else
        @include('organizations._back', ['href' => route('organizations.index'), 'label' => __('organization.screens.title')])
    @endif
@endsection

@section('title', $unit->name)

@section('content')
<p class="-mt-3 mb-5 text-sm text-ios-secondary">
    {{ $isRoot ? __('organization.screens.organization') : __('organization.screens.unit') }}
    @if ($path->isNotEmpty())
        · {{ __('organization.screens.path') }}:
        @foreach ($path as $ancestor)
            <a href="{{ route('organizations.show', $ancestor->public_id) }}" class="text-ios-blue">{{ $ancestor->name }}</a>{{ $loop->last ? '' : ' › ' }}
        @endforeach
    @endif
</p>

@if (in_array(true, $actions, true))
    <x-ui.section :title="__('organization.screens.manage')">
        @if ($actions['create_unit'])
            <x-ui.row-link :href="route('organizations.units.create', $unit->public_id)" :label="__('organization.screens.action_create_unit')" />
        @endif
        @if ($actions['rename'])
            <x-ui.row-link :href="route('organizations.rename.edit', $unit->public_id)" :label="__('organization.screens.action_rename')" />
        @endif
        @if ($actions['move'])
            <x-ui.row-link :href="route('organizations.move.choose', $unit->public_id)" :label="__('organization.screens.action_move')" />
        @endif
        @if ($actions['archive'])
            <x-ui.row-link :href="route('organizations.archive.confirm', $unit->public_id)" :label="__('organization.screens.action_archive')" destructive />
        @endif
    </x-ui.section>
@else
    <p class="mb-6 px-4 text-sm text-ios-secondary">{{ __('organization.screens.no_actions') }}</p>
@endif

<x-ui.section :title="__('organization.screens.sub_units')">
    @forelse ($subUnits as $row)
        <x-ui.row-link :href="route('organizations.show', $row['unit']->public_id)" :label="$row['unit']->name" :indent="$row['depth'] - 1" />
    @empty
        <p class="px-4 py-3 text-ios-secondary">{{ __('organization.screens.no_sub_units') }}</p>
    @endforelse
</x-ui.section>

@if ($canSeeArchived)
    <x-ui.section :footer="__('organization.screens.archived_units_hint')">
        <x-ui.row-link :href="route('organizations.archived', $unit->public_id)" :label="__('organization.screens.archived_units')" />
    </x-ui.section>
@endif
@endsection
