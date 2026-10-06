<?php

namespace App\Domain\Platform\Localization;

use App\Models\User;

/**
 * Language of messages sent to a recipient — e-mail now, SMS and notifications later (E3.6b, Z-036, area 2).
 * Separate from the language of the interface (LocaleResolver, area 1): it never reads the session or the
 * request, only the language saved on the recipient's account, limited to the languages messages exist in
 * (`localization.message_languages`); otherwise the default language. Organizer content in several languages
 * (area 3) is data of its own stages, not part of either mechanism.
 */
final class RecipientLocale
{
    public function forAccount(User $account): string
    {
        return in_array($account->locale, config('localization.message_languages'), true)
            ? $account->locale
            : config('localization.default');
    }

    /** Language for messages sent to a PERSON: the language of its account, if it has one. */
    public function forPerson(int $personId): string
    {
        $account = User::query()->where('person_id', $personId)->first();

        return $account === null ? config('localization.default') : $this->forAccount($account);
    }
}
