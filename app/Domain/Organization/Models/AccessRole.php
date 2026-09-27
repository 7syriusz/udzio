<?php

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Enums\DataClass;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** ACCESS ROLE: a set of PERMISSIONs defined by an organization (A5 §2.4). Change it through the role actions. */
class AccessRole extends Model implements ClassifiesData
{
    use AuditsChanges, HasUlids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'active_name_key'];

    protected static function booted(): void
    {
        static::updating(function (self $role): void {
            if ($role->isDirty(['public_id', 'organization_id'])) {
                throw new LogicException('A role cannot move to another organization or change its identifier.');
            }
            if ($role->getOriginal('status') === AccessRoleStatus::Retired) {
                throw new LogicException('A retired role cannot change.');
            }
        });
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'status' => AccessRoleStatus::class];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** A retired role grants nothing. */
    public function grants(Permission $permission): bool
    {
        return $this->status === AccessRoleStatus::Active && in_array($permission->value, $this->permissions, true);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Access roles are retired, not deleted.');
    }

    public function auditSubjectType(): string
    {
        return 'access_role';
    }

    public function auditOrganizationId(): ?string
    {
        return $this->organization->public_id;
    }

    public function dataClassification(): array
    {
        return array_fill_keys(['public_id', 'organization_id', 'name', 'permissions', 'status'], DataClass::Internal);
    }
}
