<?php

return [

    /*
    | Komunikaty dostępu (E3.6b). Przyczyna odmowy (kod techniczny, np. role_not_in_catalog) trafia do
    | audytu, nigdy do użytkownika.
    */

    'unauthorized' => 'Nie masz uprawnień do wykonania tej czynności.',
    'mfa_required' => 'Ta czynność wymaga uwierzytelniania dwuskładnikowego. Włącz je w ustawieniach bezpieczeństwa konta, a uprawnienia zaczną działać.',
    'email_unverified' => 'Potwierdź adres e-mail konta, aby korzystać z uprawnień przypisanej roli.',
    'mfa_setup' => 'Przejdź do ustawień bezpieczeństwa konta',

];
