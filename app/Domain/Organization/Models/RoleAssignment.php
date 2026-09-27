<?php

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\OrganizationHierarchy;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Concerns\HasValidityPeriod;
use App\Domain\Platform\Enums\DataClass;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * ACCOUNT → ACCESS ROLE in SCOPE, for a period (A5 §2.4, E1.5). Holding an assignment is not access by
 * itself: the decision (permission ∧ scope ∧ active assignment) belongs to E3.6.
 */
class RoleAssignment extends Model implements ClassifiesData
{
    use AuditsChanges, HasUlids, HasValidityPeriod;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $assignment): void {
            if (array_diff(array_keys($assignment->getDirty()), ['valid_to', 'updated_at']) !== []) {
                throw new LogicException('Role assignments cannot be rewritten; open a new period.');
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

    public static function validityKey(): array
    {
        return ['user_id', 'access_role_id', 'scope_organization_id'];
    }

    protected function casts(): array
    {
        return ['scope_inheritance' => ScopeInheritance::class, 'requested_until' => 'immutable_datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AccessRole::class, 'access_role_id');
    }

    public function scopeOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'scope_organization_id');
    }

    /**
     * Internal IDs of the organizations this assignment's SCOPE covers at `$at`, following its explicit
     * inheritance policy and the structure valid at that moment.
     *
     * @return list<int>
     */
    public function coveredOrganizationIds(DateTimeInterface $at): array
    {
        $ids = [$this->scope_organization_id];
        if ($this->scope_inheritance === ScopeInheritance::UnitAndDescendants) {
            $ids = [...$ids, ...app(OrganizationHierarchy::class)->descendantsAt($this->scopeOrganization, $at)->modelKeys()];
        }

        return $ids;
    }

    public function covers(Organization $organization, DateTimeInterface $at): bool
    {
        return in_array($organization->id, $this->coveredOrganizationIds($at), true);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Role assignment history cannot be deleted.');
    }

    public function auditSubjectType(): string
    {
        return 'role_assignment';
    }

    public function auditOrganizationId(): ?string
    {
        return $this->scopeOrganization->public_id;
    }

    public function dataClassification(): array
    {
        return array_fill_keys(['public_id', 'user_id', 'access_role_id', 'scope_organization_id', 'scope_inheritance', 'requested_until', 'requested_by_type', 'requested_by_id', 'status', 'valid_from', 'valid_to'], DataClass::Internal);
    }
}
