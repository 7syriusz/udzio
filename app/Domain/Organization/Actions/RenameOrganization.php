<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames an organization or a unit, audited before/after (E3.10a1, Z-043): a whole organization (a root) needs
 * `organization.manage`, a unit needs `structure.manage` over that unit — a local administrator renames their part
 * of the structure, not the organization.
 */
final class RenameOrganization
{
    public function __construct(
        private readonly AuditReason $reason,
        private readonly AccessDecider $access,
        private readonly OperationCorrelation $operation,
    ) {}

    public function handle(Organization $organization, string $name, string $reason): Organization
    {
        $name = OrganizationName::validate($name);

        return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($organization, $name): Organization {
            $current = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status === OrganizationStatus::Archived) {
                throw ValidationException::withMessages(['organization' => __('organization.validation.archived_cannot_be_renamed')]);
            }
            $this->access->authorize($current->isRootAt(CarbonImmutable::now('UTC')) ? Permission::OrganizationManage : Permission::StructureManage, $current);

            $current->name = $name;
            $current->save();

            return $current;
        })));
    }
}
