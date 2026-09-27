<?php

/*
| Policies per data class (A5 §12, Z-017). A scenario classifies fields on its models; these policies
| decide what the platform does with them. Change a policy here, not in the models. The values below are
| the recommended defaults awaiting Jakub's acceptance (docs/ZALOZENIA.md, Z-017).
|
| audit_values:   audit of changes stores the value (false: "[REDACTED]" marker, the change stays visible)
| audit_reads:    reading the field is recorded as a protected read (field names only)
| export:         value | redacted | omitted
| retention_days: days kept after the end of the purpose (e.g. the person's last relation); null = while
|                 the record exists. Enforcement (scheduled clean-up) is added with the retention stage.
| erasure:        keep | anonymize | delete — what retention end or an erasure request does to the value;
|                 settlement and reporting history is never destroyed (A5 §12)
*/

return [
    'policies' => [
        'public' => ['audit_values' => true, 'audit_reads' => false, 'export' => 'value', 'retention_days' => null, 'erasure' => 'keep'],
        'internal' => ['audit_values' => true, 'audit_reads' => false, 'export' => 'value', 'retention_days' => null, 'erasure' => 'keep'],
        'restricted' => ['audit_values' => false, 'audit_reads' => false, 'export' => 'value', 'retention_days' => 730, 'erasure' => 'anonymize'],
        'special_category' => ['audit_values' => false, 'audit_reads' => true, 'export' => 'redacted', 'retention_days' => 365, 'erasure' => 'delete'],
        'secret' => ['audit_values' => false, 'audit_reads' => true, 'export' => 'omitted', 'retention_days' => 0, 'erasure' => 'delete'],
    ],
];
