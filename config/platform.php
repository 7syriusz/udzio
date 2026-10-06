<?php

/*
| Platform administration (E3.8b). Functional assumptions are listed in docs/ZALOZENIA.md (Z-039).
*/

return [
    // Platform roles: a set of platform permissions and a security policy. Every platform permission is
    // privileged, so MFA is required whatever this flag says; the flag records the policy explicitly.
    'roles' => [
        'administrator' => [
            'permissions' => ['platform.administrators.manage', 'platform.mfa.reset', 'platform.person_links.resolve'],
            'requires_mfa' => true,
        ],
    ],

    // Role given to the account created by the installation command.
    'first_administrator_role' => 'administrator',
];
