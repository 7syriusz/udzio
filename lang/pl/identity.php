<?php

return [

    /*
    | Tożsamość: kontakty i reprezentacja (E2, przeniesione do tłumaczeń w E3.6b).
    */

    'channels' => [
        'email' => 'E-mail',
        'phone' => 'Telefon',
    ],

    'contact' => [
        'duplicate' => 'Ten kontakt jest już przypisany do tej osoby.',
        'too_many_code_requests' => 'Zbyt wiele próśb o kod. Spróbuj później.',
        'phone_verification_unavailable' => 'Potwierdzanie numerów telefonu nie jest jeszcze dostępne.',
        'code_invalid' => 'Kod jest nieprawidłowy albo wygasł.',
    ],

    'representation_methods' => [
        'parties_acceptance' => 'akceptacja stron',
        'declaration' => 'oświadczenie',
        'role_decision' => 'decyzja uprawnionej roli',
        'document' => 'dokument',
        'additional_verification' => 'dodatkowa weryfikacja',
    ],

    'representation_scopes' => [
        'profile' => [
            'view' => 'podgląd danych osoby',
            'update' => 'zmiana danych osoby',
        ],
        'contacts' => [
            'view' => 'podgląd kontaktów',
            'manage' => 'zarządzanie kontaktami',
        ],
        'registrations' => [
            'manage' => 'zgłoszenia',
        ],
        'payments' => [
            'manage' => 'płatności',
        ],
        'consents' => [
            'manage' => 'zgody',
        ],
    ],

];
