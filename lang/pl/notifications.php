<?php

return [

    /*
    | Treści wiadomości wysyłanych przez system (e-mail; później SMS i powiadomienia). Język wiadomości
    | ustala się dla odbiorcy (LocaleResolver::forAccount), niezależnie od języka bieżącego ekranu.
    | Szablony komunikacji organizatora będą osobnym mechanizmem (E10).
    */

    'contact_verification' => [
        'subject' => 'Kod potwierdzenia adresu e-mail',
        'code' => 'Twój kod potwierdzenia: :code',
        'validity' => 'Kod jest ważny przez :minutes min. Jeśli to nie Ty, zignoruj tę wiadomość.',
    ],

    'mfa_reset' => [
        'subject' => 'Uwierzytelnianie dwuskładnikowe na Twoim koncie zostało zresetowane',
        'done' => 'Administrator wyłączył uwierzytelnianie dwuskładnikowe na Twoim koncie, unieważnił dotychczasowe kody odzyskiwania i zakończył wszystkie sesje.',
        'next' => 'Zaloguj się i ponownie włącz uwierzytelnianie dwuskładnikowe w ustawieniach bezpieczeństwa konta — do tego czasu uprawnienia administracyjne nie działają.',
        'not_you' => 'Jeśli reset nie został wykonany na Twoją prośbę, niezwłocznie skontaktuj się z administratorem platformy.',
    ],

];
