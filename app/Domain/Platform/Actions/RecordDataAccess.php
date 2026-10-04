<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Enums\AuditResult;

/**
 * Records reads that must always be audited (E3.7b, Z-037): exports, bulk reads, reports with personal data,
 * administrative or emergency access, and reads by a technical process under SystemAuthority. Ordinary
 * allowed list views are not recorded. Written on the independent `audit` connection, like protected reads;
 * only metadata (counts, field names, purpose) is stored, never the values.
 */
final class RecordDataAccess
{
    public const EXPORT = 'data.exported';

    public const BULK_READ = 'data.bulk_read';

    public const REPORT = 'report.downloaded';

    public const PRIVILEGED = 'access.privileged';

    public const SYSTEM_READ = 'data.read_by_system';

    public function __construct(private readonly RecordAudit $audit) {}

    /** @param array<string, mixed> $details */
    public function handle(string $action, string $subjectType, string $subjectId, array $details, ?string $organizationId = null, ?string $purpose = null): void
    {
        $this->audit->handle($action, $subjectType, $subjectId, AuditResult::Succeeded, organizationId: $organizationId, reason: $purpose, after: $details, connection: 'audit');
    }
}
