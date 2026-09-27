<?php

namespace App\Domain\Platform\Classification;

use App\Domain\Platform\Enums\DataClass;
use LogicException;

/** What the platform does with a field of a given class. Values come from config/data_classification.php. */
final readonly class ClassPolicy
{
    public const REDACTED = '[REDACTED]';

    public function __construct(
        /** Audit of changes stores the value itself (otherwise the redaction marker). */
        public bool $auditValues,
        /** Reading the field must be recorded as a protected read. */
        public bool $auditReads,
        public ExportMode $export,
        /** Days the value is kept after the end of its purpose (e.g. the last relation); null = as long as the record exists. */
        public ?int $retentionDays,
        public ErasureMode $erasure,
    ) {}

    public static function for(DataClass $class): self
    {
        $policy = config('data_classification.policies.'.$class->value);
        if (! is_array($policy)) {
            throw new LogicException("No data classification policy for {$class->value}.");
        }

        return new self(
            (bool) $policy['audit_values'],
            (bool) $policy['audit_reads'],
            ExportMode::from($policy['export']),
            $policy['retention_days'] === null ? null : (int) $policy['retention_days'],
            ErasureMode::from($policy['erasure']),
        );
    }
}
