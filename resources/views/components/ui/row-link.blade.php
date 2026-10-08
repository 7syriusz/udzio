@props(['href', 'label', 'detail' => null, 'destructive' => false, 'indent' => 0])
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'flex min-h-12 items-center gap-3 border-b border-ios-separator px-4 py-3 last:border-b-0 hover:bg-ios-bg focus-visible:bg-ios-bg focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ios-blue']) }}
   @if ($indent > 0) style="padding-left: calc(1rem + {{ min($indent, 6) }} * 1.25rem)" @endif>
    <span class="min-w-0 flex-1 break-words {{ $destructive ? 'text-ios-red' : 'text-ios-label' }}">{{ $label }}</span>
    @if ($detail)
        <span class="shrink-0 text-sm text-ios-secondary">{{ $detail }}</span>
    @endif
    <svg aria-hidden="true" class="size-4 shrink-0 text-ios-secondary" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="m7 4 6 6-6 6"/></svg>
</a>
