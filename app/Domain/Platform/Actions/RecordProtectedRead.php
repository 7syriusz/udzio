<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Enums\AuditResult;
use InvalidArgumentException;

/**
 * Records that protected data was read (A5 §12: audit of reads adequate to risk). Only the names
 * of the fields that were read are stored — never their values. Written on the independent
 * `audit` connection: a read that happened stays recorded even if the surrounding transaction
 * is rolled back. Which fields require it is decided by the data classification (E1.7).
 */
final class RecordProtectedRead
{
    public function __construct(private readonly RecordAudit $audit) {}

    /** @param list<string> $fields */
    public function handle(string $subjectType, string $subjectId, array $fields, ?string $organizationId = null, ?string $purpose = null): void
    {
        if ($fields === [] || array_filter($fields, fn ($field) => ! is_string($field) || ! preg_match('/^[a-z][a-z0-9_.]*$/', $field)) !== []) {
            throw new InvalidArgumentException('Protected read requires a non-empty list of field names.');
        }
        $fields = array_values(array_unique($fields));
        sort($fields);

        $this->audit->handle(
            'data.read',
            $subjectType,
            $subjectId,
            AuditResult::Succeeded,
            organizationId: $organizationId,
            reason: $purpose,
            after: ['fields' => $fields],
            connection: 'audit',
        );
    }
}
