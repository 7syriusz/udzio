<form method="POST" action="{{ $action }}" class="max-w-md" novalidate>
    @csrf
    @method('PUT')
    @foreach ([['given_name', __('account.person.given_name')], ['family_name', __('account.person.family_name')]] as [$field, $label])
        <div class="mb-4">
            <label for="{{ $field }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
            <input id="{{ $field }}" name="{{ $field }}" type="text" required value="{{ old($field, $person->{$field}) }}"
                   class="w-full rounded border border-gray-300 px-3 py-2">
            @error($field) <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
    @endforeach
    <button type="submit" class="rounded bg-blue-700 px-4 py-2 font-medium text-white">{{ __('ui.actions.save') }}</button>
</form>
