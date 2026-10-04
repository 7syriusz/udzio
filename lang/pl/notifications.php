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

];
