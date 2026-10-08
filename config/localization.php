<?php

/*
| Languages of the system texts (E3.6b, Z-036). Only system translations live in lang/; notification
| templates and multilingual organizer content get their own mechanisms in later stages.
*/

return [
    // Default and fallback language; also used when nothing else decides (LocaleResolver).
    'default' => 'pl',

    // Languages a user, a session or a public entry may choose. Adding one = adding lang/<code>/ files
    // and listing it here; a missing key in that language falls back to the default.
    'supported' => ['pl'],

    // Languages of messages sent to a recipient (e-mail, later SMS and notifications) — RecipientLocale.
    // Kept apart from the interface languages: a message language is listed once its templates exist.
    'message_languages' => ['pl'],

    // How dates and times are shown (E3.10c). Stored values stay in UTC; they are shown in this time zone and in the
    // order day, month, year of the interface language. Native browser date pickers are not used: they follow the
    // browser's language (e.g. month first), not the page's (ArchitectureTest).
    'display_timezone' => 'Europe/Warsaw',
    'date_formats' => [
        'pl' => ['date' => 'd.m.Y', 'datetime' => 'd.m.Y, H:i', 'date_hint' => 'dd.mm.rrrr'],
    ],

    // Session key of an explicit choice made in the current session.
    'session_key' => 'locale',
];
