<?php

return [

    /*
    | Strony i odpowiedzi błędów HTTP (E3.6b). Szczegóły techniczne nigdy nie są pokazywane użytkownikowi.
    */

    'title' => 'Błąd :status',
    'code' => 'Kod błędu: :status',
    'home' => 'Przejdź do strony głównej',
    'back' => 'Wróć do poprzedniej strony',

    'http' => [
        '401' => 'Wymagane zalogowanie.',
        '402' => 'Wymagana płatność.',
        '403' => 'Nie masz dostępu do tej strony.',
        '404' => 'Nie znaleziono strony.',
        '405' => 'Ta operacja nie jest dostępna pod tym adresem.',
        '419' => 'Strona wygasła. Odśwież ją i spróbuj ponownie.',
        '429' => 'Zbyt wiele prób. Spróbuj ponownie za chwilę.',
        '500' => 'Wystąpił błąd serwera. Spróbuj ponownie później.',
        '503' => 'Usługa jest chwilowo niedostępna.',
    ],

];
