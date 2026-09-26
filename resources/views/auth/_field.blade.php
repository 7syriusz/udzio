<div class="mb-4">
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type ?? 'text' }}" autocomplete="{{ $autocomplete ?? 'off' }}"
           @if (($type ?? 'text') !== 'password') value="{{ old($name) }}" @endif
           @if ($required ?? true) required @endif
           class="w-full rounded border border-gray-300 px-3 py-2 @error($name) border-red-500 @enderror"
           @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror>
    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
    @enderror
</div>
