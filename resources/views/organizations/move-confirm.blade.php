@extends('layouts.app')

@section('back')
    @include('organizations._back', ['href' => route('organizations.move.choose', $unit->public_id), 'label' => __('organization.screens.move_choose')])
@endsection

@section('title', __('organization.screens.move_confirm_title'))

@section('content')
<x-ui.section>
    <dl>
        <div class="border-b border-ios-separator px-4 py-3"><dt class="text-sm text-ios-secondary">{{ __('organization.screens.move_what') }}</dt><dd class="font-semibold break-words">{{ $unit->name }}</dd></div>
        <div class="border-b border-ios-separator px-4 py-3"><dt class="text-sm text-ios-secondary">{{ __('organization.screens.move_from') }}</dt><dd class="break-words">{{ $from }}</dd></div>
        <div class="px-4 py-3"><dt class="text-sm text-ios-secondary">{{ __('organization.screens.move_to') }}</dt><dd class="break-words">{{ $to }}</dd></div>
    </dl>
</x-ui.section>

<x-ui.section :title="__('organization.screens.sub_units')">
    @if ($effect['sub_units']->isEmpty())
        <p class="px-4 py-3">{{ __('organization.screens.move_no_sub_units') }}</p>
    @else
        <p class="px-4 pt-3">{{ __('organization.screens.move_sub_units', ['count' => $effect['sub_units']->count()]) }}</p>
        <ul class="list-disc px-4 pt-1 pb-3 pl-9">
            @foreach ($effect['sub_units'] as $sub)
                <li class="break-words">{{ $sub->name }}</li>
            @endforeach
        </ul>
    @endif
</x-ui.section>

<x-ui.section :title="__('organization.screens.move_access')">
    <div class="space-y-1 px-4 py-3">
        @if ($effect['losing'] === 0 && $effect['gaining'] === 0)
            <p>{{ __('organization.screens.move_access_unchanged') }}</p>
        @else
            <p>{{ trans_choice('organization.screens.move_access_losing', $effect['losing'], ['from' => $fromName]) }}</p>
            <p>{{ trans_choice('organization.screens.move_access_gaining', $effect['gaining'], ['to' => $target->name]) }}</p>
        @endif
        <p class="text-sm text-ios-secondary">{{ __('organization.screens.move_access_kept') }}</p>
    </div>
</x-ui.section>

<form method="POST" action="{{ route('organizations.move', $unit->public_id) }}" novalidate>
    @csrf
    @method('PUT')
    <input type="hidden" name="parent" value="{{ $target->public_id }}">
    <x-ui.actions :cancel="route('organizations.show', $unit->public_id)" :submit="__('organization.screens.move_submit')" />
</form>
@endsection
