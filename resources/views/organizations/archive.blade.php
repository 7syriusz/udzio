@extends('layouts.app')

@section('title', __('organization.screens.archive_title', ['name' => $organization->name]))

@section('content')
<div class="mb-6 rounded border border-red-300 bg-red-50 p-4 text-red-900" role="alert">
    {{ $isRoot ? __('organization.screens.archive_root_warning') : __('organization.screens.archive_unit_warning') }}
</div>
<form method="POST" action="{{ route('organizations.archive', $organization->public_id) }}" class="max-w-xl">
    @csrf
    @include('auth._field', ['name' => 'reason', 'label' => __('organization.screens.reason')])
    @if ($isRoot)
        @include('auth._field', ['name' => 'confirmation', 'label' => __('organization.screens.archive_confirmation', ['name' => $organization->name])])
    @endif
    <div class="flex items-center gap-4">
        <button type="submit" class="rounded bg-red-700 px-4 py-2 text-white">{{ __('organization.screens.archive_submit') }}</button>
        <a href="{{ route('organizations.show', $organization->public_id) }}" class="underline">{{ __('organization.screens.cancel') }}</a>
    </div>
</form>
@endsection
