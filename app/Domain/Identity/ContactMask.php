<?php

namespace App\Domain\Identity;

/** Partly hidden contact data for someone without the right to full contact data (E3.7b). */
final class ContactMask
{
    /** anna.nowak@example.pl → a•••@e•••.pl */
    public static function email(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $dot = strrpos($domain, '.');
        $host = $dot === false ? $domain : substr($domain, 0, $dot);
        $suffix = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($local, 0, 1).'•••@'.mb_substr($host, 0, 1).'•••'.$suffix;
    }
}
