@props(['name', 'label', 'value' => '', 'hint' => null])
<div class="px-4 py-3">
    <label for="{{ $name }}" class="mb-1 block text-sm text-ios-secondary">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="text" value="{{ old($name, $value) }}" autocomplete="off"
           @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
           {{ $attributes->merge(['class' => 'w-full rounded-lg border border-ios-separator bg-ios-card px-3 py-2.5 text-base text-ios-label focus:border-ios-blue focus:outline-2 focus:outline-ios-blue']) }}>
    @if ($hint)
        <p class="mt-1 text-sm text-ios-secondary">{{ $hint }}</p>
    @endif
    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-sm text-ios-red">{{ $message }}</p>
    @enderror
</div>
