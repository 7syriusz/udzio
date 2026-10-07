<?php

return [

    /*
    | Komunikaty logowania (Laravel, Fortify) i teksty ekranów uwierzytelniania (E3.6b).
    */

    'failed' => 'Nieprawidłowy e-mail lub hasło.',
    'password' => 'Podane hasło jest nieprawidłowe.',
    'throttle' => 'Zbyt wiele prób logowania. Spróbuj ponownie za :seconds s.',

    'fields' => [
        'email' => 'E-mail',
        'password' => 'Hasło',
        'new_password' => 'Nowe hasło (co najmniej :min znaków — może być zdanie ze spacjami)',
        'password_with_rule' => 'Hasło (co najmniej :min znaków — może być zdanie ze spacjami)',
        'password_confirmation' => 'Powtórz hasło',
        'given_name' => 'Imię',
        'family_name' => 'Nazwisko',
    ],

    'screens' => [
        'login' => [
            'title' => 'Logowanie',
            'remember' => 'Zapamiętaj mnie',
            'submit' => 'Zaloguj',
            'forgot' => 'Nie pamiętam hasła',
            'no_account' => 'Nie masz konta?',
        ],
        'register' => [
            'title' => 'Zakładanie konta',
            'submit' => 'Załóż konto',
            'has_account' => 'Masz już konto?',
            'login' => 'Zaloguj się',
        ],
        'confirm_password' => [
            'title' => 'Potwierdź hasło',
            'intro' => 'To ustawienie bezpieczeństwa. Potwierdź je hasłem.',
            'submit' => 'Potwierdź',
        ],
        'forgot_password' => [
            'title' => 'Nie pamiętam hasła',
            'intro' => 'Podaj adres e-mail konta. Jeśli konto istnieje, wyślemy link do ustawienia nowego hasła.',
            'submit' => 'Wyślij link',
            'sent' => 'Jeśli konto z tym adresem istnieje, wysłaliśmy link do ustawienia nowego hasła.',
        ],
        'reset_password' => [
            'title' => 'Nowe hasło',
            'submit' => 'Ustaw hasło',
        ],
        'two_factor_challenge' => [
            'title' => 'Kod weryfikacyjny',
            'intro' => 'Podaj kod z aplikacji uwierzytelniającej albo jeden z kodów odzyskiwania.',
            'code' => 'Kod z aplikacji',
            'recovery_code' => 'albo kod odzyskiwania',
            'submit' => 'Potwierdź',
        ],
        'verify_email' => [
            'title' => 'Potwierdź adres e-mail',
            'link_sent' => 'Wysłaliśmy nowy link potwierdzający.',
            'intro' => 'Kliknij link, który wysłaliśmy na adres :email. Po potwierdzeniu połączymy konto z Twoimi danymi w Udzio.',
            'resend' => 'Wyślij link ponownie',
        ],
    ],

];
