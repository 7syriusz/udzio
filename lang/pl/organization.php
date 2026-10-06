<?php

return [

    /*
    | Organizacja, struktura, członkostwo i role (E3, przeniesione do tłumaczeń w E3.6b).
    */

    'screens' => [
        'title' => 'Organizacje',
        'intro' => 'Organizacje i jednostki, które możesz przeglądać.',
        'empty' => 'Nie masz jeszcze dostępu do żadnej organizacji.',
        'found' => 'Załóż organizację',
        'found_title' => 'Nowa organizacja',
        'found_intro' => 'Zostaniesz administratorem tej organizacji. Nie daje to uprawnień do innych organizacji ani do całej platformy.',
        'found_mfa_note' => 'Zarządzanie organizacją wymaga włączonego uwierzytelniania dwuskładnikowego.',
        'name' => 'Nazwa',
        'reason' => 'Powód zmiany',
        'founded' => 'Organizacja „:name” została założona.',
        'founded_needs_mfa' => 'Aby zarządzać organizacją, włącz uwierzytelnianie dwuskładnikowe. Do tego czasu uprawnienia administratora nie działają.',
        'awaiting_mfa_title' => 'Organizacje czekające na włączenie uwierzytelniania dwuskładnikowego',
        'awaiting_mfa' => 'Masz w nich rolę, która zacznie działać po włączeniu uwierzytelniania dwuskładnikowego.',
        'go_to_security' => 'Przejdź do ustawień bezpieczeństwa',
        'structure' => 'Struktura',
        'path' => 'Położenie',
        'status_active' => 'aktywna',
        'status_archived' => 'zarchiwizowana',
        'add_unit' => 'Dodaj jednostkę podrzędną',
        'unit_name' => 'Nazwa jednostki',
        'rename' => 'Zmień nazwę',
        'new_name' => 'Nowa nazwa',
        'move' => 'Przenieś do',
        'archive' => 'Archiwizuj',
        'unit_created' => 'Jednostka została utworzona.',
        'renamed' => 'Nazwa została zmieniona.',
        'moved' => 'Jednostka została przeniesiona.',
        'archived' => '„:name” została zarchiwizowana.',
        'archive_title' => 'Archiwizacja: :name',
        'archive_root_warning' => 'Archiwizujesz całą organizację razem ze wszystkimi jednostkami. Organizacja przestanie być aktywna: nikt nie będzie mógł w niej działać ani nią zarządzać. Historia, audyt, przypisania ról i dawna struktura zostaną zachowane — nic nie zostanie usunięte.',
        'archive_unit_warning' => 'Archiwizujesz jednostkę. Przestanie być aktywna, a uprawnienia w niej przestaną działać. Historia, audyt, przypisania ról i jej dawne położenie zostaną zachowane.',
        'archive_confirmation' => 'Aby potwierdzić, wpisz dokładnie nazwę organizacji: :name',
        'archive_confirmation_mismatch' => 'Wpisana nazwa nie zgadza się z nazwą organizacji.',
        'archive_submit' => 'Zarchiwizuj',
        'cancel' => 'Anuluj',
        'open' => 'otwórz',
    ],

    'founding' => [
        'too_many_attempts' => 'Zbyt wiele prób założenia organizacji. Spróbuj ponownie później.',
    ],

    'validation' => [
        'representation_policy_missing' => 'Organizacja nie przewiduje ustanawiania takiej reprezentacji przez uprawnioną osobę.',
        'representation_document_required' => 'Wpisz dokument lub podstawę, którą sprawdzono.',
        'representation_scope_not_allowed' => 'Ta podstawa nie pozwala nadać wybranego zakresu działania.',
        'representation_until_required' => 'Podaj datę zakończenia reprezentacji (najwyżej :days dni).',
        'representation_person_not_covered' => 'Ta podstawa nie dotyczy wskazanej osoby.',
        'organization_inactive' => 'Organizacja musi być aktywna.',
        'archived_cannot_be_renamed' => 'Nie można zmienić nazwy zarchiwizowanej organizacji.',
        'move_requires_active_units' => 'Przenoszona jednostka i nowy rodzic muszą być aktywni.',
        'move_creates_cycle' => 'Przeniesienie utworzyłoby cykl w strukturze.',
        'role_name_taken' => 'Organizacja ma już aktywną rolę o tej nazwie.',
        'grant_catalog_requires_assign' => 'Katalog nadawania ról wymaga uprawnienia „nadawanie ról”.',
        'grant_catalog_foreign_role' => 'Katalog może wskazywać tylko aktywne role tej organizacji lub jej jednostek.',
        'until_in_past' => 'Termin wygaśnięcia musi być w przyszłości.',
        'role_retired' => 'Nie można nadać wycofanej roli.',
        'scope_inactive' => 'Zakres musi być aktywną jednostką.',
        'scope_outside_role_organization' => 'Rolę można nadać tylko w organizacji, która ją zdefiniowała, albo w jej jednostkach.',
        'request_expired' => 'Wnioskowany termin już minął; potrzebne nowe nadanie.',
    ],

    'candidates' => [
        'query_too_short' => 'Wpisz co najmniej :min znaki, aby wyszukać osobę.',
        'too_many_searches' => 'Zbyt wiele wyszukiwań. Spróbuj ponownie za chwilę.',
    ],

];
