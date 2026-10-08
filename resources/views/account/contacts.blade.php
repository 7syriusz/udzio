@extends('layouts.app')

@section('title', __('account.contacts.title'))

@section('content')
<table class="mb-8 w-full text-left">
    <thead><tr><th class="py-2">{{ __('account.contacts.channel') }}</th><th>{{ __('account.contacts.address') }}</th><th>{{ __('account.contacts.state') }}</th><th></th></tr></thead>
    <tbody>
    @forelse ($contacts as $contact)
        <tr class="border-t">
            <td class="py-2">{{ $contact->channel->label() }}</td>
            <td>{{ $contact->value }}</td>
            <td>
                @if ($contact->isVerified())
                    {{ __('account.contacts.verified') }}
                @elseif (! $contact->canBeVerified())
                    {{ __('account.contacts.unverified_unavailable') }}
                @else
                    {{ __('account.contacts.unverified') }}
                    <form method="POST" action="{{ route('account.contacts.verification.send', $contact) }}" class="inline" novalidate>
                        @csrf
                        <button class="text-blue-700 underline">{{ __('account.contacts.send_code') }}</button>
                    </form>
                    <form method="POST" action="{{ route('account.contacts.verify', $contact) }}" class="mt-1 flex gap-2" novalidate>
                        @csrf
                        <label class="sr-only" for="code-{{ $contact->public_id }}">{{ __('account.contacts.code') }}</label>
                        <input id="code-{{ $contact->public_id }}" name="code" inputmode="numeric" autocomplete="one-time-code" class="w-28 rounded border px-2 py-1">
                        <button class="text-blue-700 underline">{{ __('account.contacts.confirm') }}</button>
                    </form>
                @endif
            </td>
            <td>
                <form method="POST" action="{{ route('account.contacts.destroy', $contact) }}" novalidate>
                    @csrf
                    @method('DELETE')
                    <button class="text-red-700 underline">{{ __('ui.actions.remove') }}</button>
                </form>
            </td>
        </tr>
    @empty
        <tr><td colspan="4" class="py-2">{{ __('account.contacts.empty') }}</td></tr>
    @endforelse
    </tbody>
</table>
@error('code') <p class="mb-4 text-red-700">{{ $message }}</p> @enderror
@error('contact') <p class="mb-4 text-red-700">{{ $message }}</p> @enderror

<h2 class="mb-2 text-lg font-semibold">{{ __('account.contacts.add_title') }}</h2>
<form method="POST" action="{{ route('account.contacts.store') }}" class="flex max-w-xl flex-wrap items-end gap-2" novalidate>
    @csrf
    <div>
        <label for="channel" class="block text-sm">{{ __('account.contacts.channel') }}</label>
        <select id="channel" name="channel" class="rounded border px-2 py-2">
            @foreach (\App\Domain\Identity\Enums\ContactChannel::cases() as $channel)
                <option value="{{ $channel->value }}">{{ $channel->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="grow">
        <label for="value" class="block text-sm">{{ __('account.contacts.value') }}</label>
        <input id="value" name="value" required value="{{ old('value') }}" class="w-full rounded border px-3 py-2">
    </div>
    <button type="submit" class="rounded bg-blue-700 px-4 py-2 font-medium text-white">{{ __('ui.actions.add') }}</button>
</form>
@error('value') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
@endsection
