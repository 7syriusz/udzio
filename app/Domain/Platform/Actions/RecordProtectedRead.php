<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Classification\ClassifiedData;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Enums\AuditResult;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Records that protected data was read (A5 §12: audit of reads adequate to risk). Only the names
 * of the fields that were read are stored — never their values. Written on the independent
 * `audit` connection: a read that happened stays recorded even if the surrounding transaction
 * is rolled back. Which fields require it is decided by the data classification (E1.7): use forModel().
 */
final class RecordProtectedRead
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * Records the read only if a field's class requires it (config/data_classification.php).
     *
     * @param  Model&ClassifiesData  $model  an audited model (uses AuditsChanges)
     * @param  list<string>  $fields  fields that were read
     */
    public function forModel(Model&ClassifiesData $model, array $fields, ?string $purpose = null): bool
    {
        if (! in_array(AuditsChanges::class, class_uses_recursive($model), true)) {
            throw new InvalidArgumentException('Protected reads are recorded for audited models only.');
        }
        $audited = ClassifiedData::readAudited($model, $fields);
        if ($audited === []) {
            return false;
        }
        $this->handle($model->auditSubjectType(), (string) $model->getKey(), $audited, $model->auditOrganizationId(), $purpose);

        return true;
    }

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
