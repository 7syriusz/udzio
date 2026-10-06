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
        'history' => [
            'view' => 'podgląd historii zarchiwizowanych jednostek',
        ],
    ],
    'members' => [
        'view' => 'podgląd członków',
        'manage' => 'zarządzanie członkostwem',
        'history' => [
            'view' => 'podgląd byłych członków i historii członkostwa',
        ],
    ],
    'roles' => [
        'manage' => 'definiowanie ról',
        'assign' => 'nadawanie ról',
        'audit' => [
            'view' => 'kontrola ról (wszystkie przypisania w zakresie)',
        ],
    ],
    'people' => [
        'contacts' => [
            'view' => 'pełne dane kontaktowe osób',
        ],
        'protected' => [
            'view' => 'dane szczególnie chronione osób',
        ],
    ],
    'data' => [
        'export' => 'eksporty i masowe odczyty danych',
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
    'platform' => [
        'administrators' => [
            'manage' => 'zarządzanie administratorami platformy',
        ],
        'mfa' => [
            'reset' => 'reset uwierzytelniania dwuskładnikowego innego konta',
        ],
        'install' => 'utworzenie pierwszego administratora platformy (tylko instalacja)',
        'emergency' => [
            'mfa_reset' => 'awaryjny reset uwierzytelniania dwuskładnikowego (tylko konsola serwera)',
        ],
    ],

];
