<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Models\Contact;
use App\Domain\Identity\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Adds an unverified contact to its owner. The same address may belong to several people (a shared
 * family e-mail); it never joins them into one PERSON.
 */
final class AddContact
{
    public function handle(Person $owner, ContactChannel $channel, string $value): Contact
    {
        try {
            $normalized = $channel->normalize($value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['value' => __('validation.'.($channel === ContactChannel::Email ? 'email' : 'regex'), ['attribute' => 'value'])]);
        }

        try {
            return Contact::create(['person_id' => $owner->id, 'channel' => $channel, 'value' => $normalized]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'active_key')) {
                throw ValidationException::withMessages(['value' => 'Ten kontakt jest już przypisany do tej osoby.']);
            }
            throw $e;
        }
    }
}
