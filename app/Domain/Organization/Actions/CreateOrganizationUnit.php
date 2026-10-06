<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a unit below an existing one (`structure.manage` over the parent, authorized centrally, E3.10a). The
 * unit is the same ORGANIZATION with a parent period from now (E3.2); nobody gets a role in it automatically —
 * rights reach it through the inheritance policy of existing assignments.
 */
final class CreateOrganizationUnit
{
    public function __construct(
        private readonly CreateOrganization $create,
        private readonly AccessDecider $access,
        private readonly AuditReason $reason,
        private readonly OperationCorrelation $operation,
    ) {}

    public function handle(Organization $parent, string $name, string $reason): Organization
    {
        return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($parent, $name): Organization {
            // Same graph mutex as MoveOrganization: the oldest organization row.
            Organization::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $currentParent = Organization::query()->whereKey($parent->getKey())->lockForUpdate()->firstOrFail();
            if ($currentParent->status !== OrganizationStatus::Active) {
                throw ValidationException::withMessages(['parent' => __('organization.validation.move_requires_active_units')]);
            }
            $this->access->authorize(Permission::StructureManage, $currentParent);
            $unit = $this->create->handle($name);
            OrganizationParent::startPeriod(['organization_id' => $unit->id, 'parent_id' => $currentParent->id], CarbonImmutable::now('UTC'));

            return $unit;
        })));
    }
}
