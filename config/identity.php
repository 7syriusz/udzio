<?php

/*
| Identity settings (E2). Functional assumptions are listed in docs/ZALOZENIA.md (Z-020).
*/

return [
    'contacts' => [
        // Country calling code added to national phone numbers written without a prefix.
        'default_phone_country_code' => '48',
        'verification' => [
            'code_length' => 6,
            'ttl_minutes' => 30,
            'max_attempts' => 5,
            'max_requests_per_hour' => 5,
        ],
    ],
];
