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

    'validation' => [
        'role_unknown' => 'Nieznana rola platformy.',
    ],

];
