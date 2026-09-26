<form method="POST" action="{{ $action }}" class="max-w-md">
    @csrf
    @method('PUT')
    @foreach ([['given_name', 'Imię', 'text'], ['family_name', 'Nazwisko', 'text'], ['birth_date', 'Data urodzenia', 'date']] as [$field, $label, $type])
        <div class="mb-4">
            <label for="{{ $field }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
            <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" @if ($field !== 'birth_date') required @endif
                   value="{{ old($field, $field === 'birth_date' ? $person->birth_date?->format('Y-m-d') : $person->{$field}) }}"
                   class="w-full rounded border border-gray-300 px-3 py-2">
            @error($field) <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
    @endforeach
    <button type="submit" class="rounded bg-blue-700 px-4 py-2 font-medium text-white">Zapisz</button>
</form>
