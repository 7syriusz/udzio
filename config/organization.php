<?php

/*
| Organization and access settings (E3). Functional assumptions are listed in docs/ZALOZENIA.md (Z-037).
*/

return [
    'history' => [
        // How long ended memberships stay visible to holders of members.history.view, in days after the
        // period ended. null = no limit yet: a PROVISIONAL setting until the retention policy is decided
        // (Z-017); data is not deleted automatically.
        'visible_days' => null,
    ],

    'candidate_search' => [
        // Choosing an account for a role (E3.7b): minimum query length, maximum results, searches per minute.
        'min_query_length' => 3,
        'max_results' => 10,
        'max_per_minute' => 20,
    ],

    'privileged_access' => [
        // Permissions treated as privileged (E3.8): a role holding any of them gives nothing until the account
        // has a verified e-mail and confirmed MFA. A role may also require MFA by its own policy (requires_mfa).
        'permissions' => [
            'roles.manage',
            'roles.assign',
            'roles.audit.view',
            'audit.view',
            'people.protected.view',
            'data.export',
        ],
    ],

    'reads' => [
        // A list read returning more rows than this is a bulk read and is audited (E3.7b).
        'bulk_threshold' => 200,
    ],
];
