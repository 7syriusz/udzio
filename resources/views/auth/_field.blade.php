<div class="mb-4">
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
    @if (($type ?? 'text') === 'password')
        <x-ui.password-input :name="$name" :autocomplete="$autocomplete ?? 'current-password'" :invalid="$errors->has($name)" :describedby="$errors->has($name) ? $name.'-error' : null" />
    @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type ?? 'text' }}" autocomplete="{{ $autocomplete ?? 'off' }}" value="{{ old($name) }}"
               @if ($required ?? true) required @endif
               class="w-full rounded-lg border border-ios-separator bg-ios-card px-3 py-2.5 text-base focus:border-ios-blue focus:outline-2 focus:outline-ios-blue @error($name) border-ios-red @enderror"
               @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror>
    @endif
    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-sm text-ios-red">{{ $message }}</p>
    @enderror
</div>
