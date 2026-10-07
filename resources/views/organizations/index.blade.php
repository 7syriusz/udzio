@extends('layouts.app')

@section('title', __('organization.screens.title'))

@section('content')
@if (session('founded_needs_mfa') && $awaitingMfa->isEmpty())
    <div class="mb-6 rounded border border-amber-300 bg-amber-50 p-4" role="alert">
        <p class="mb-2">{{ __('organization.screens.founded_needs_mfa') }}</p>
        <a href="{{ route('account.security') }}" class="font-semibold text-blue-700 underline">{{ __('organization.screens.go_to_security') }}</a>
    </div>
@endif

@if ($awaitingMfa->isNotEmpty())
    <section class="mb-6 rounded border border-amber-300 bg-amber-50 p-4">
        <h2 class="mb-1 font-semibold">{{ __('organization.screens.awaiting_mfa_title') }}</h2>
        <p class="mb-2">{{ __('organization.screens.awaiting_mfa') }}</p>
        <ul class="mb-2 list-disc pl-6">
            @foreach ($awaitingMfa as $waiting)
                <li>{{ $waiting->name }}</li>
            @endforeach
        </ul>
        <a href="{{ route('account.security') }}" class="font-semibold text-blue-700 underline">{{ __('organization.screens.go_to_security') }}</a>
    </section>
@endif

<p class="mb-4">{{ __('organization.screens.intro') }}</p>
<ul class="mb-6 divide-y rounded border bg-white">
    @forelse ($organizations as $organization)
        <li class="flex items-center justify-between p-3">
            <span>{{ $organization->name }}</span>
            <a href="{{ route('organizations.show', $organization->public_id) }}" class="text-blue-700 underline">{{ __('organization.screens.open') }}</a>
        </li>
    @empty
        <li class="p-3">{{ $awaitingMfa->isEmpty() ? __('organization.screens.empty') : __('organization.screens.empty_until_mfa') }}</li>
    @endforelse
</ul>

<a href="{{ route('organizations.create') }}" class="inline-block rounded bg-blue-700 px-4 py-2 text-white">{{ __('organization.screens.found') }}</a>
@endsection
