<?php

return [

    /*
    | Administracja platformą i instalacja (E3.8b).
    */

    'install' => [
        'given_name' => 'Imię pierwszego administratora',
        'family_name' => 'Nazwisko pierwszego administratora',
        'done' => 'Utworzono konto pierwszego administratora platformy: :email. Na ten adres wysłano link do ustawienia hasła i link potwierdzający adres e-mail.',
        'next_steps' => 'Uprawnienia administratora zaczną działać dopiero po potwierdzeniu adresu e-mail i włączeniu uwierzytelniania dwuskładnikowego w ustawieniach bezpieczeństwa konta.',
        'already_installed' => 'Platforma jest już zainstalowana. Procedury utworzenia pierwszego administratora nie można użyć ponownie.',
        'email_taken' => 'Pod tym adresem istnieje już konto. Pierwszy administrator musi otrzymać nowe konto.',
        'invalid' => 'Nie utworzono administratora — popraw dane:',
    ],

    'emergency' => [
        'account_missing' => 'Nie znaleziono konta o tym adresie e-mail.',
        'ask_reason' => 'Powód awaryjnego resetu',
        'ask_identity' => 'Jak potwierdzono tożsamość właściciela konta?',
        'summary' => 'Awaryjny reset MFA konta :email (:name): zostaną usunięte uwierzytelnianie dwuskładnikowe, kody odzyskiwania i wszystkie sesje. Żadne uprawnienia nie zostaną nadane.',
        'ask_confirm' => 'Aby potwierdzić, wpisz ponownie adres e-mail konta',
        'not_confirmed' => 'Operacja nie została potwierdzona — nic nie zmieniono. Próba została zapisana w audycie.',
        'invalid' => 'Nie wykonano resetu — uzupełnij dane:',
        'done' => 'Zresetowano uwierzytelnianie dwuskładnikowe konta :email i zakończono jego sesje. Właściciel konta otrzymał powiadomienie e-mail.',
        'next_steps' => 'Uprawnienia platformy tego konta zaczną działać dopiero po ponownym włączeniu uwierzytelniania dwuskładnikowego.',
    ],

    'validation' => [
        'role_unknown' => 'Nieznana rola platformy.',
    ],

];
