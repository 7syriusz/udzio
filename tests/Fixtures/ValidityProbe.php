<?php

namespace Tests\Fixtures;

use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Concerns\HasValidityPeriod;
use Illuminate\Database\Eloquent\Model;

/** Test model: a person's function in a context, as a relation in time (E1.5). */
class ValidityProbe extends Model
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

    public function auditVisibleFields(): array
    {
        return ['person_ref', 'context_ref', 'function', 'status', 'valid_from', 'valid_to'];
    }

    public function auditRedactedFields(): array
    {
        return [];
    }
}
