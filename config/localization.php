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

    // Session key of an explicit choice made in the current session.
    'session_key' => 'locale',
];
