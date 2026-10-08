<?php

return [

    /*
    | Ekrany konta (E2.8, przeniesione do tłumaczeń w E3.6b).
    */

    'person' => [
        'title' => 'Moje dane',
        'review_pending' => 'Aby bezpiecznie połączyć konto z Twoimi danymi, potrzebna jest dodatkowa weryfikacja. Zgłoszenie zostało przyjęte (numer :number). Skontaktujemy się z Tobą; do tego czasu konto działa bez danych osobowych.',
        'not_linked' => 'Konto nie jest jeszcze połączone z Twoimi danymi osobowymi. Potwierdź adres e-mail, aby je połączyć.',
        'given_name' => 'Imię',
        'family_name' => 'Nazwisko',
        'full_name' => 'Imię i nazwisko',
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
        'two_factor' => 'Weryfikacja dwuetapowa',
        'two_factor_explained' => 'Przy logowaniu oprócz hasła podajesz 6-cyfrowy kod z aplikacji na telefonie (np. Google Authenticator lub Microsoft Authenticator). Bez niej nie działają uprawnienia administratora organizacji.',
        'state' => 'Stan',
        'state_enabled' => 'Włączona',
        'state_disabled' => 'Wyłączona',
        'state_pending' => 'Rozpoczęta — czeka na potwierdzenie kodem',
        'recovery_codes' => 'Kody odzyskiwania — zapisz je w bezpiecznym miejscu. Każdy działa jeden raz, gdy nie masz telefonu.',
        'regenerate_codes' => 'Wygeneruj nowe kody odzyskiwania',
        'regenerate_codes_hint' => 'Poprzednie kody przestaną działać.',
        'disable' => 'Wyłącz weryfikację dwuetapową',
        'enable' => 'Włącz weryfikację dwuetapową',
        'scan' => '1. Zeskanuj kod QR aplikacją uwierzytelniającą na telefonie.',
        'scan_code' => '2. Wpisz 6-cyfrowy kod, który pokaże aplikacja.',
        'code' => 'Kod z aplikacji',
        'confirm' => 'Potwierdź',
        'cancel_setup' => 'Anuluj konfigurację',
        'cancel_setup_hint' => 'Kod QR przestanie działać. Możesz zacząć od nowa w dowolnym momencie.',
        'change_password' => 'Zmiana hasła',
        'current_password' => 'Obecne hasło',
        'new_password' => 'Nowe hasło (co najmniej :min znaków — może być zdanie ze spacjami)',
        'new_password_confirmation' => 'Powtórz nowe hasło',
        'change_password_submit' => 'Zmień hasło',
        'other_devices' => 'Wyloguj inne urządzenia',
        'other_devices_explained' => 'Zakończy logowanie na wszystkich innych komputerach i telefonach, np. gdy zapomnisz się wylogować na cudzym urządzeniu. To urządzenie pozostanie zalogowane. Potwierdź hasłem.',
        'password' => 'Hasło',
        'logout_other_devices' => 'Wyloguj inne urządzenia',
        'other_devices_logged_out' => 'Wylogowano inne urządzenia.',
    ],

];
