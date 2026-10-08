@props(['title' => null, 'footer' => null])
<section {{ $attributes->merge(['class' => 'mb-6']) }}>
    @if ($title)
        <h2 class="mb-1.5 px-4 text-xs font-medium tracking-wide text-ios-secondary uppercase">{{ $title }}</h2>
    @endif
    <div class="overflow-hidden rounded-xl border border-ios-separator bg-ios-card">
        {{ $slot }}
    </div>
    @if ($footer)
        <p class="mt-1.5 px-4 text-sm text-ios-secondary">{{ $footer }}</p>
    @endif
</section>
