<?php

return [

    /*
    | Organizacja, struktura, członkostwo i role (E3, przeniesione do tłumaczeń w E3.6b).
    */

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
