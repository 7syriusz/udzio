@extends('layouts.app')

@section('back')
    @include('organizations._back', ['href' => route('organizations.show', $unit->public_id), 'label' => $unit->name])
@endsection

@section('title', __('organization.screens.move_title', ['name' => $unit->name]))

@section('content')
<x-ui.section :title="__('organization.screens.move_from')">
    <p class="px-4 py-3 break-words">{{ $from }}</p>
</x-ui.section>

<form method="GET" action="{{ route('organizations.move.confirm', $unit->public_id) }}" novalidate>
    <fieldset>
        <legend class="mb-1.5 px-4 text-xs font-medium tracking-wide text-ios-secondary uppercase">{{ __('organization.screens.move_choose') }}</legend>
        <div class="mb-1 overflow-hidden rounded-xl border border-ios-separator bg-ios-card">
            @foreach ($targets as $option)
                <label class="flex min-h-12 cursor-pointer items-center gap-3 border-b border-ios-separator px-4 py-3 last:border-b-0 has-[:checked]:bg-ios-bg has-[:focus-visible]:outline-2 has-[:focus-visible]:-outline-offset-2 has-[:focus-visible]:outline-ios-blue">
                    <input type="radio" name="parent" value="{{ $option['unit']->public_id }}" class="size-5 accent-ios-blue" @checked(old('parent') === $option['unit']->public_id)>
                    <span class="min-w-0 break-words">{{ $option['path'] }}</span>
                </label>
            @endforeach
        </div>
        @error('parent') <p class="mb-2 px-4 text-sm text-ios-red">{{ $message }}</p> @enderror
    </fieldset>
    <x-ui.actions :cancel="route('organizations.show', $unit->public_id)" :submit="__('organization.screens.move_next')" />
</form>
@endsection
