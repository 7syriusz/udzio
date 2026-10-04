<?php

return [

    /*
    | Nazwy uprawnień (PERMISSION, E3.4) pokazywane w interfejsie. Klucze to techniczne nazwy uprawnień.
    */

    'organization' => [
        'view' => 'podgląd organizacji',
        'manage' => 'zarządzanie organizacją (nazwa, archiwizacja)',
    ],
    'structure' => [
        'manage' => 'zarządzanie strukturą jednostek',
    ],
    'members' => [
        'view' => 'podgląd członków',
        'manage' => 'zarządzanie członkostwem',
    ],
    'roles' => [
        'manage' => 'definiowanie ról',
        'assign' => 'nadawanie ról',
    ],
    'person_links' => [
        'resolve' => 'rozstrzyganie połączeń kont z osobami',
    ],
    'representations' => [
        'establish' => 'ustanawianie reprezentacji',
    ],
    'audit' => [
        'view' => 'podgląd audytu',
    ],

];
