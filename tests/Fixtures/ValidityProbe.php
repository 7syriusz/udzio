<?php

namespace Tests\Fixtures;

use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Concerns\HasValidityPeriod;
use App\Domain\Platform\Enums\DataClass;
use Illuminate\Database\Eloquent\Model;

/** Test model: a person's function in a context, as a relation in time (E1.5). */
class ValidityProbe extends Model implements ClassifiesData
{
    use AuditsChanges, HasValidityPeriod;

    protected $table = 'validity_probes';

    public static function validityKey(): array
    {
        return ['person_ref', 'context_ref'];
    }

    public function auditSubjectType(): string
    {
        return 'validity_probe';
    }

    public function auditOrganizationId(): ?string
    {
        return $this->context_ref;
    }

    public function dataClassification(): array
    {
        return [
            'person_ref' => DataClass::Internal,
            'context_ref' => DataClass::Internal,
            'function' => DataClass::Internal,
            'status' => DataClass::Internal,
            'valid_from' => DataClass::Internal,
            'valid_to' => DataClass::Internal,
        ];
    }
}
