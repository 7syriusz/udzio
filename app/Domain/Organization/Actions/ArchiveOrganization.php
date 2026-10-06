<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use Illuminate\Support\Facades\DB;

/** Archives an organization or unit without deleting it (`organization.manage`, authorized centrally, E3.10a). */
final class ArchiveOrganization
{
    public function __construct(
        private readonly AuditReason $reason,
        private readonly AccessDecider $access,
        private readonly OperationCorrelation $operation,
    ) {}

    public function handle(Organization $organization, string $reason): Organization
    {
        return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($organization): Organization {
            $current = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status === OrganizationStatus::Archived) {
                return $current;
            }
            $this->access->authorize(Permission::OrganizationManage, $current);

            $current->status = OrganizationStatus::Archived;
            $current->archived_at = now('UTC');
            $current->save();

            return $current;
        })));
    }
}
