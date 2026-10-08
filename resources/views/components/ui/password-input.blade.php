@props(['name', 'id' => null, 'autocomplete' => 'current-password', 'invalid' => false, 'describedby' => null])
@php($fieldId = $id ?? $name)
<div class="relative">
    <input id="{{ $fieldId }}" name="{{ $name }}" type="password" autocomplete="{{ $autocomplete }}"
           @if ($invalid) aria-invalid="true" @endif @if ($describedby) aria-describedby="{{ $describedby }}" @endif
           {{ $attributes->merge(['class' => 'w-full rounded-lg border border-ios-separator bg-ios-card py-2.5 pr-12 pl-3 text-base text-ios-label focus:border-ios-blue focus:outline-2 focus:outline-ios-blue']) }}>
    {{-- Shown by resources/js/app.js; without JavaScript the password simply stays hidden. --}}
    <button type="button" hidden data-password-toggle aria-controls="{{ $fieldId }}" aria-pressed="false"
            aria-label="{{ __('ui.password.show') }}" data-show-label="{{ __('ui.password.show') }}" data-hide-label="{{ __('ui.password.hide') }}"
            class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-ios-secondary hover:text-ios-label focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ios-blue">
        <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
</div>
