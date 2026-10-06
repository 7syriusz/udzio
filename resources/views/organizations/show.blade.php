@extends('layouts.app')

@section('title', $organization->name)

@section('content')
@if ($ancestors->isNotEmpty())
    <p class="mb-4 text-sm text-gray-700">
        {{ __('organization.screens.path') }}:
        @foreach ($ancestors as $ancestor)
            <a href="{{ route('organizations.show', $ancestor->public_id) }}" class="text-blue-700 underline">{{ $ancestor->name }}</a> /
        @endforeach
        {{ $organization->name }}
    </p>
@endif

@if ($errors->any())
    <div class="mb-4 rounded border border-red-300 bg-red-50 p-3 text-red-800" role="alert">
        <ul class="list-disc pl-6">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<h2 class="mb-2 text-lg font-semibold">{{ __('organization.screens.structure') }}</h2>
<ul class="rounded border bg-white p-3">
    @include('organizations._node', ['node' => $tree, 'abilities' => $abilities])
</ul>
@endsection
