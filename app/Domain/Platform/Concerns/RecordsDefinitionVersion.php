<?php

namespace App\Domain\Platform\Concerns;

use App\Domain\Platform\Models\DefinitionVersion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Result side of "definition → version → result": every result names the exact version it was produced
 * by (A5-11), and that link never changes (A5-16). Migration: `$table->foreignId('definition_version_id')
 * ->constrained('definition_versions')->restrictOnDelete()`.
 */
trait RecordsDefinitionVersion
{
    /** The definition type (auditSubjectType of the definition) this result may point to. */
    abstract public static function definitionType(): string;

    public static function bootRecordsDefinitionVersion(): void
    {
        static::saving(function (self $result): void {
            if ($result->exists && $result->isDirty('definition_version_id')) {
                throw new LogicException('A result cannot change the definition version it was produced by.');
            }
            if (! $result->exists) {
                $version = DefinitionVersion::on($result->getConnectionName())->find($result->definition_version_id);
                if ($version === null || $version->definition_type !== static::definitionType()) {
                    throw new LogicException('A result must reference a published version of '.static::definitionType().'.');
                }
            }
        });
    }

    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(DefinitionVersion::class);
    }
}
