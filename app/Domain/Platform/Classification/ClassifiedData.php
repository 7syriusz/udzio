<?php

namespace App\Domain\Platform\Classification;

use App\Domain\Platform\Enums\DataClass;
use LogicException;

/** Applies the class policies of a model's fields to values leaving the model (audit, export, reads). */
final class ClassifiedData
{
    /**
     * Values as they may be stored in the audit trail.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function forAudit(ClassifiesData $model, array $values): array
    {
        $result = [];
        foreach ($values as $field => $value) {
            $result[$field] = $value === null || self::classOf($model, $field)->policy()->auditValues ? $value : ClassPolicy::REDACTED;
        }

        return $result;
    }

    /**
     * Values as they may appear in an export. Unclassified fields are never exported.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function forExport(ClassifiesData $model, array $values): array
    {
        $classification = $model->dataClassification();
        $result = [];
        foreach ($values as $field => $value) {
            $mode = isset($classification[$field]) ? $classification[$field]->policy()->export : ExportMode::Omitted;
            match ($mode) {
                ExportMode::Value => $result[$field] = $value,
                ExportMode::Redacted => $result[$field] = $value === null ? null : ClassPolicy::REDACTED,
                ExportMode::Omitted => null,
            };
        }

        return $result;
    }

    /**
     * Fields among `$fields` whose reading must be recorded as a protected read.
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function readAudited(ClassifiesData $model, array $fields): array
    {
        return array_values(array_filter($fields, fn (string $field) => self::classOf($model, $field)->policy()->auditReads));
    }

    private static function classOf(ClassifiesData $model, string $field): DataClass
    {
        return $model->dataClassification()[$field]
            ?? throw new LogicException('Field '.$model::class."::{$field} has no data classification.");
    }
}
