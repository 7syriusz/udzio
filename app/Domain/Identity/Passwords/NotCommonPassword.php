<?php

namespace App\Domain\Identity\Passwords;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Local check of common and obvious passwords (E3.8e, Z-041), independent of any external service. Compares
 * case-insensitively and ignores spaces (the password itself is never changed): a listed password, one
 * character or a short unit repeated, or a run of a keyboard, digit or alphabet sequence.
 */
final class NotCommonPassword implements ValidationRule
{
    /** Keyboard rows, digits and the alphabet, forwards; their backwards runs are checked too. */
    private const SEQUENCES = ['0123456789012345678901234567890', 'abcdefghijklmnopqrstuvwxyz', 'qwertyuiopasdfghjklzxcvbnm', 'qwertzuiopasdfghjklyxcvbnm', 'azertyuiopqsdfghjklmwxcvbn'];

    /** @var array<string, true>|null */
    private static ?array $list = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::isCommon($value)) {
            $fail('validation.password.uncompromised')->translate();
        }
    }

    public static function isCommon(string $password): bool
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', '', $password));
        if ($normalized === '') {
            return true;
        }

        return isset(self::list()[$normalized])
            || preg_match('/^(.{1,4})\1+$/us', $normalized) === 1
            || self::isSequence($normalized);
    }

    private static function isSequence(string $normalized): bool
    {
        foreach (self::SEQUENCES as $sequence) {
            if (str_contains($sequence, $normalized) || str_contains(strrev($sequence), $normalized)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private static function list(): array
    {
        if (self::$list === null) {
            $lines = file(resource_path('security/common-passwords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $entries = array_filter(array_map(fn (string $line) => mb_strtolower(preg_replace('/\s+/u', '', $line)), $lines), fn (string $line) => $line !== '' && ! str_starts_with($line, '#'));
            self::$list = array_fill_keys($entries, true);
        }

        return self::$list;
    }
}
