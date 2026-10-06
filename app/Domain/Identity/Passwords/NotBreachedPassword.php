<?php

namespace App\Domain\Identity\Passwords;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Breach check of a password (E3.8e, Z-041) through BreachedPasswordVerifier. Skipped for a password the
 * local rules already refuse, so no request is made for it. Passes when the service is unavailable.
 */
final class NotBreachedPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < (int) config('identity.passwords.min_length') || NotCommonPassword::isCommon($value)) {
            return;
        }
        if (! app(BreachedPasswordVerifier::class)->verify(['value' => $value, 'threshold' => 0])) {
            $fail('validation.password.uncompromised')->translate();
        }
    }
}
