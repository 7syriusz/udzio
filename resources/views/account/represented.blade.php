@extends('layouts.app')

@section('title', __('account.represented.title'))

@section('content')
<ul>
@forelse ($representations as $representation)
    <li class="mb-2">
        <a class="text-blue-700 underline" href="{{ route('account.represented.show', $representation->represented) }}">{{ $representation->represented->fullName() }}</a>
        <span class="text-sm text-gray-600">{{ __('account.represented.details', ['method' => $representation->method->label(), 'scopes' => collect($representation->scopes)->map(fn (string $scope) => \App\Domain\Identity\Enums\RepresentationScope::tryFrom($scope)?->label() ?? $scope)->implode(', ')]) }}</span>
    </li>
@empty
    <li>{{ __('account.represented.empty') }}</li>
@endforelse
</ul>
@endsection
