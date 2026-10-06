<?php

namespace App\Domain\Platform\Localization;

use App\Domain\Platform\AuditReason;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use InvalidArgumentException;

/**
 * The one place that decides the language of system texts (E3.6b, Z-036). Order:
 * 1. an explicit choice made in the current session (also for a public entry without an account);
 * 2. the language saved on the account;
 * 3. the default of the context (organization, public page) — passed in by the caller once such contexts
 *    have a language setting;
 * 4. the default language (Polish), which is also the fallback for a missing translation.
 * A value outside the supported list is ignored at every step. Never derived from country, IP address
 * or organization data alone. Only the interface and system messages (area 1); the language of messages sent
 * to a recipient is RecipientLocale (area 2); organizer content in several languages is data (area 3).
 */
final class LocaleResolver
{
    /** @return list<string> */
    public function supported(): array
    {
        return config('localization.supported');
    }

    public function default(): string
    {
        return config('localization.default');
    }

    public function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, $this->supported(), true);
    }

    public function resolve(?Session $session, ?User $account, ?string $contextDefault = null): string
    {
        foreach ([$session?->get(config('localization.session_key')), $account?->locale, $contextDefault] as $candidate) {
            if ($this->isSupported($candidate)) {
                return $candidate;
            }
        }

        return $this->default();
    }

    /** An explicit choice: kept for the session and, for a signed-in account, saved on the account. */
    public function choose(string $locale, ?Session $session, ?User $account): void
    {
        if (! $this->isSupported($locale)) {
            throw new InvalidArgumentException('Unsupported locale.');
        }
        $session?->put(config('localization.session_key'), $locale);
        if ($account !== null && $account->locale !== $locale) {
            app(AuditReason::class)->because('account holder chose language', fn () => $account->forceFill(['locale' => $locale])->save());
        }
    }
}
