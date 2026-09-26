@extends('layouts.app')

@section('title', 'Kontakty')

@section('content')
<table class="mb-8 w-full text-left">
    <thead><tr><th class="py-2">Kanał</th><th>Adres</th><th>Stan</th><th></th></tr></thead>
    <tbody>
    @forelse ($contacts as $contact)
        <tr class="border-t">
            <td class="py-2">{{ $contact->channel === \App\Domain\Identity\Enums\ContactChannel::Email ? 'E-mail' : 'Telefon' }}</td>
            <td>{{ $contact->value }}</td>
            <td>
                @if ($contact->isVerified())
                    potwierdzony
                @else
                    niepotwierdzony
                    <form method="POST" action="{{ route('account.contacts.verification.send', $contact) }}" class="inline">
                        @csrf
                        <button class="text-blue-700 underline">wyślij kod</button>
                    </form>
                    <form method="POST" action="{{ route('account.contacts.verify', $contact) }}" class="mt-1 flex gap-2">
                        @csrf
                        <label class="sr-only" for="code-{{ $contact->public_id }}">Kod</label>
                        <input id="code-{{ $contact->public_id }}" name="code" inputmode="numeric" autocomplete="one-time-code" class="w-28 rounded border px-2 py-1">
                        <button class="text-blue-700 underline">potwierdź</button>
                    </form>
                @endif
            </td>
            <td>
                <form method="POST" action="{{ route('account.contacts.destroy', $contact) }}">
                    @csrf
                    @method('DELETE')
                    <button class="text-red-700 underline">usuń</button>
                </form>
            </td>
        </tr>
    @empty
        <tr><td colspan="4" class="py-2">Brak kontaktów.</td></tr>
    @endforelse
    </tbody>
</table>
@error('code') <p class="mb-4 text-red-700">{{ $message }}</p> @enderror
@error('contact') <p class="mb-4 text-red-700">{{ $message }}</p> @enderror

<h2 class="mb-2 text-lg font-semibold">Dodaj kontakt</h2>
<form method="POST" action="{{ route('account.contacts.store') }}" class="flex max-w-xl flex-wrap items-end gap-2">
    @csrf
    <div>
        <label for="channel" class="block text-sm">Kanał</label>
        <select id="channel" name="channel" class="rounded border px-2 py-2">
            <option value="email">E-mail</option>
            <option value="phone">Telefon</option>
        </select>
    </div>
    <div class="grow">
        <label for="value" class="block text-sm">Adres lub numer</label>
        <input id="value" name="value" required value="{{ old('value') }}" class="w-full rounded border px-3 py-2">
    </div>
    <button type="submit" class="rounded bg-blue-700 px-4 py-2 font-medium text-white">Dodaj</button>
</form>
@error('value') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
@endsection
