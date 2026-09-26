<?php

namespace App\Domain\Platform\Versioning;

/** SHA-256 of a snapshot in canonical JSON: object keys sorted, list order kept. */
final class ContentHash
{
    /** @param array<array-key, mixed> $content */
    public static function of(array $content): string
    {
        return hash('sha256', json_encode(self::canonical($content), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
