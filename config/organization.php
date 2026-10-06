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
            // Linking an account to a person and establishing a representation open a person's data to an
            // account (E3.9, Z-042).
            'person_links.resolve',
            'representations.establish',
        ],
    ],

    'founding' => [
        // Role the founder of a new organization receives in it (E3.10a, Z-043) — rights in that organization only,
        // never platform rights. Its catalog covers every role of the organization and its units ('*'), so the
        // founder can hand out roles defined later; it requires MFA (privileged permissions and its own policy).
        'founder_role' => [
            'name' => 'Administrator organizacji',
            'permissions' => [
                'organization.view', 'organization.manage', 'structure.manage', 'structure.history.view',
                'members.view', 'members.manage', 'members.history.view',
                'roles.manage', 'roles.assign', 'roles.audit.view', 'audit.view',
                'person_links.resolve', 'representations.establish', 'people.contacts.view',
            ],
            'grant_rules' => [['role' => '*', 'include_descendants' => true]],
            'requires_mfa' => true,
        ],
    ],

    'representation_policies' => [
        // When a role may establish a representation (E3.9a, Z-042) — set by a scenario or the organization's
        // configuration; none by default, so no role establishes one. Each policy names:
        //   'method'        => 'document' | 'role_decision' (the ground the role records; acceptance and declaration
        //                      come from the parties themselves, never from a role),
        //   'document'      => what must be checked (shown to the operator, recorded in the audit),
        //   'represented'   => ['functions' => list of membership functions or null, 'max_age' => int|null],
        //   'scopes'        => representation scopes the representative may receive,
        //   'max_days'      => longest period (an end date is then required) or null,
        //   'organizations' => public IDs of organizations whose units may use it, or null for all.
        // Example: 'guardian_of_minor' => ['method' => 'document', 'document' => 'dokument potwierdzający opiekę',
        //   'represented' => ['functions' => ['member'], 'max_age' => 17], 'scopes' => ['profile.view',
        //   'registrations.manage', 'consents.manage'], 'max_days' => 365, 'organizations' => null],
    ],

    'reads' => [
        // A list read returning more rows than this is a bulk read and is audited (E3.7b).
        'bulk_threshold' => 200,
    ],
];
