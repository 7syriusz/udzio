<?php

namespace App\Domain\Platform\Localization;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The one way dates are shown and read in the interface, messages and reports (E3.10c, Z-036). Values are stored
 * in UTC; they are shown in `localization.display_timezone` and in the order of the current language — Polish:
 * day, month, year (dd.mm.rrrr). A typed date is read in the same order. Views never format dates themselves.
 */
final class DateDisplay
{
    public function date(?DateTimeInterface $value): string
    {
        return $value === null ? '' : $this->local($value)->format($this->format('date'));
    }

    public function dateTime(?DateTimeInterface $value): string
    {
        return $value === null ? '' : $this->local($value)->format($this->format('datetime'));
    }

    /** Placeholder telling the user the expected order of a typed date, e.g. "dd.mm.rrrr". */
    public function hint(): string
    {
        return $this->format('date_hint');
    }

    /** A date typed in the interface order (e.g. 08.10.2026), or null when it is not a real date in that order. */
    public function parseDate(string $typed): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!'.$this->format('date'), trim($typed), config('localization.display_timezone'));
        } catch (InvalidArgumentException) {
            return null;
        }

        // Rejects overflowing dates (31.02 would roll over to March) and any other order.
        return $parsed instanceof CarbonImmutable && $parsed->format($this->format('date')) === trim($typed) ? $parsed : null;
    }

    private function local(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)->setTimezone(config('localization.display_timezone'));
    }

    private function format(string $kind): string
    {
        $formats = config('localization.date_formats');

        return $formats[app()->getLocale()][$kind] ?? $formats[config('localization.default')][$kind];
    }
}
