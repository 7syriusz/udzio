<?php

return [

    /*
    | Wspólne elementy interfejsu (E3.6b).
    */

    'welcome' => [
        'title' => 'Witamy w Udzio',
        'intro' => 'Zaloguj się, aby zarządzać swoimi danymi i udziałem w wydarzeniach.',
    ],

    'nav' => [
        'label' => 'Konto',
        'account' => 'Moje dane',
        'contacts' => 'Kontakty',
        'represented' => 'Osoby reprezentowane',
        'security' => 'Bezpieczeństwo',
        'organizations' => 'Organizacje',
        'logout' => 'Wyloguj',
    ],

    'actions' => [
        'save' => 'Zapisz',
        'add' => 'Dodaj',
        'remove' => 'usuń',
        'confirm' => 'Potwierdź',
    ],

    'status' => [
        'saved' => 'Zapisano.',
    ],

    // Statuses flashed by Fortify as technical codes (E3.10g) — shown only through these texts.
    'status_codes' => [
        'two-factor-authentication-enabled' => 'Zeskanuj kod QR w aplikacji na telefonie i wpisz kod, aby dokończyć włączanie weryfikacji dwuetapowej.',
        'two-factor-authentication-confirmed' => 'Weryfikacja dwuetapowa jest włączona. Zapisz kody odzyskiwania w bezpiecznym miejscu.',
        'two-factor-authentication-disabled' => 'Weryfikacja dwuetapowa jest wyłączona.',
        'recovery-codes-generated' => 'Wygenerowano nowe kody odzyskiwania. Poprzednie przestały działać.',
        'password-updated' => 'Hasło zostało zmienione.',
        'verification-link-sent' => 'Wysłaliśmy nowy link potwierdzający adres e-mail.',
        'profile-information-updated' => 'Zapisano.',
    ],

    'password' => [
        'show' => 'Pokaż hasło',
        'hide' => 'Ukryj hasło',
    ],

    'empty_value' => '—',

];
