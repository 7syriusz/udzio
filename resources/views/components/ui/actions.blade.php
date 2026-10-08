@props(['cancel', 'submit', 'destructive' => false])
<div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
    <a href="{{ $cancel }}" class="rounded-xl border border-ios-separator bg-ios-card px-5 py-3 text-center font-medium text-ios-blue hover:bg-ios-bg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ios-blue">{{ __('organization.screens.cancel') }}</a>
    <button type="submit" class="rounded-xl px-5 py-3 font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 {{ $destructive ? 'bg-ios-red hover:bg-red-800 focus-visible:outline-ios-red' : 'bg-ios-blue hover:bg-ios-blue-pressed focus-visible:outline-ios-blue' }}">{{ $submit }}</button>
</div>
