@extends('layouts.app')

@section('title', __('organization.screens.title'))

@section('content')
@if ($awaitingMfa->isNotEmpty())
    <x-ui.notice tone="warning">
        @if (session('founded'))
            <p class="mb-1 font-semibold">{{ __('organization.screens.mfa_needed_founded', ['name' => session('founded')]) }}</p>
        @endif
        <p class="mb-1 font-semibold">{{ __('organization.screens.mfa_needed_title') }}</p>
        <p class="mb-2">{{ __('organization.screens.mfa_needed_text') }} {{ $awaitingMfa->pluck('name')->implode(', ') }}</p>
        <a href="{{ route('account.security') }}" class="inline-block rounded-xl bg-ios-blue px-4 py-2.5 font-semibold text-white hover:bg-ios-blue-pressed focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ios-blue">{{ __('organization.screens.mfa_enable') }}</a>
    </x-ui.notice>
@endif

<x-ui.section :footer="__('organization.screens.intro')">
    @forelse ($organizations as $organization)
        <x-ui.row-link :href="route('organizations.show', $organization->public_id)" :label="$organization->name" :aria-label="__('organization.screens.open_organization', ['name' => $organization->name])" />
    @empty
        <p class="px-4 py-3 text-ios-secondary">{{ $awaitingMfa->isEmpty() ? __('organization.screens.empty') : __('organization.screens.empty_until_mfa') }}</p>
    @endforelse
</x-ui.section>

<a href="{{ route('organizations.create') }}" class="inline-block rounded-xl bg-ios-blue px-5 py-3 font-semibold text-white hover:bg-ios-blue-pressed focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ios-blue">{{ __('organization.screens.found') }}</a>
@endsection
