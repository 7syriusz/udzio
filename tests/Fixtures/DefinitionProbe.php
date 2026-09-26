<?php

namespace Tests\Fixtures;

use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Concerns\HasVersions;
use App\Domain\Platform\Enums\DataClass;
use Illuminate\Database\Eloquent\Model;

/** Test definition (e.g. a scoring rule) for the versioning pattern (E1.6). */
class DefinitionProbe extends Model implements ClassifiesData
{
    use AuditsChanges, HasVersions;

    protected $table = 'definition_probes';

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function versionSnapshot(): array
    {
        return ['name' => $this->name, 'rules' => $this->rules];
    }

    public function auditSubjectType(): string
    {
        return 'definition_probe';
    }

    public function auditOrganizationId(): ?string
    {
        return $this->organization_ref;
    }

    public function dataClassification(): array
    {
        return [
            'organization_ref' => DataClass::Internal,
            'name' => DataClass::Internal,
            'rules' => DataClass::Internal,
        ];
    }
}
