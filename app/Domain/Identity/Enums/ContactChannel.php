<?php

namespace App\Domain\Identity\Enums;

use InvalidArgumentException;

enum ContactChannel: string
{
    case Email = 'email';
    case Phone = 'phone';

    /** Name shown in the interface (lang/<locale>/identity.php). */
    public function label(): string
    {
        return __('identity.channels.'.$this->value);
    }

    /** Canonical form used for storage and comparison. Throws on a value that is not a valid address. */
    public function normalize(string $value): string
    {
        $value = trim($value);

        return match ($this) {
            self::Email => self::email($value),
            self::Phone => self::phone($value),
        };
    }

    private static function email(string $value): string
    {
        $value = mb_strtolower($value);
        if (mb_strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid e-mail address.');
        }

        return $value;
    }

    private static function phone(string $value): string
    {
        $digits = preg_replace('/[\s\-().]/', '', $value);
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        } elseif (! str_starts_with($digits, '+')) {
            $digits = '+'.config('identity.contacts.default_phone_country_code').$digits;
        }
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $digits)) {
            throw new InvalidArgumentException('Invalid phone number.');
        }

        return $digits;
    }
}
