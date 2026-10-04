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

    'reads' => [
        // A list read returning more rows than this is a bulk read and is audited (E3.7b).
        'bulk_threshold' => 200,
    ],
];
