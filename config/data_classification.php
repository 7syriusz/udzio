<?php

/*
| Policies per data class (A5 §12, Z-017). A scenario classifies fields on its models; these policies
| decide what the platform does with them. Change a policy here, not in the models.
|
| audit_values: audit of changes stores the value (false: "[REDACTED]" marker, the change stays visible)
| audit_reads:  reading the field is recorded as a protected read (field names only)
| export:       value | redacted | omitted
*/

return [
    'policies' => [
        'public' => ['audit_values' => true, 'audit_reads' => false, 'export' => 'value'],
        'internal' => ['audit_values' => true, 'audit_reads' => false, 'export' => 'value'],
        'restricted' => ['audit_values' => false, 'audit_reads' => false, 'export' => 'value'],
        'special_category' => ['audit_values' => false, 'audit_reads' => true, 'export' => 'redacted'],
        'secret' => ['audit_values' => false, 'audit_reads' => true, 'export' => 'omitted'],
    ],
];
