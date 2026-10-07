<?php

return [

    /*
    | Ekrany konta (E2.8, przeniesione do tłumaczeń w E3.6b).
    */

    'person' => [
        'title' => 'Moje dane',
        'review_pending' => 'Aby bezpiecznie połączyć konto z Twoimi danymi, potrzebna jest dodatkowa weryfikacja. Zgłoszenie zostało przyjęte (numer :number). Skontaktujemy się z Tobą; do tego czasu konto działa bez danych osobowych.',
        'not_linked' => 'Konto nie jest jeszcze połączone z Twoimi danymi osobowymi. Potwierdź adres e-mail, aby je połączyć.',
        'identifier' => 'Identyfikator osoby: :id',
        'given_name' => 'Imię',
        'family_name' => 'Nazwisko',
        'full_name' => 'Imię i nazwisko',
        'birth_date' => 'Data urodzenia',
    ],

    'contacts' => [
        'title' => 'Kontakty',
        'channel' => 'Kanał',
        'address' => 'Adres',
        'state' => 'Stan',
        'verified' => 'potwierdzony',
        'unverified' => 'niepotwierdzony',
        'unverified_unavailable' => 'niepotwierdzony (potwierdzanie tego kanału nie jest jeszcze dostępne)',
        'send_code' => 'wyślij kod',
        'code' => 'Kod',
        'confirm' => 'potwierdź',
        'empty' => 'Brak kontaktów.',
        'add_title' => 'Dodaj kontakt',
        'value' => 'Adres lub numer',
        'added' => 'Dodano kontakt.',
        'removed' => 'Usunięto kontakt.',
        'code_sent' => 'Wysłaliśmy kod.',
        'confirmed' => 'Kontakt potwierdzony.',
    ],

    'represented' => [
        'title' => 'Osoby reprezentowane',
        'details' => '(podstawa: :method; zakres: :scopes)',
        'empty' => 'Nie reprezentujesz żadnej osoby.',
    ],

    'security' => [
        'title' => 'Bezpieczeństwo konta',
        'two_factor' => 'Uwierzytelnianie dwuskładnikowe',
        'enabled' => 'Włączone.',
        'disabled' => 'Wyłączone.',
        'recovery_codes' => 'Kody odzyskiwania (zapisz je w bezpiecznym miejscu):',
        'regenerate_codes' => 'Wygeneruj nowe kody odzyskiwania',
        'disable' => 'Wyłącz',
        'enable' => 'Włącz',
        'scan' => 'Zeskanuj kod w aplikacji uwierzytelniającej i wpisz wygenerowany kod.',
        'code' => 'Kod',
        'change_password' => 'Zmiana hasła',
        'current_password' => 'Obecne hasło',
        'new_password' => 'Nowe hasło (co najmniej :min znaków — może być zdanie ze spacjami)',
        'new_password_confirmation' => 'Powtórz nowe hasło',
        'change_password_submit' => 'Zmień hasło',
        'other_devices' => 'Pozostałe urządzenia',
        'password' => 'Hasło',
        'logout_other_devices' => 'Wyloguj pozostałe urządzenia',
        'other_devices_logged_out' => 'Wylogowano pozostałe urządzenia.',
    ],

];
