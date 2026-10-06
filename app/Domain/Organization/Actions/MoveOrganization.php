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
 * Moves a unit under another parent, or makes it a root (structure.manage over the unit, its current parent and
 * the new parent, authorized centrally, E3.10a1). History of where a unit was is kept as periods (E3.2).
 */
final class MoveOrganization
{
    public function __construct(
        private readonly AuditReason $reason,
        private readonly AccessDecider $access,
        private readonly OperationCorrelation $operation,
    ) {}

    /** A null parent makes the organization a root. Moves take effect now, never retroactively. */
    public function handle(Organization $organization, ?Organization $parent, string $reason): ?OrganizationParent
    {
        return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($organization, $parent): ?OrganizationParent {
            // Organizations cannot be deleted: the oldest row is a stable mutex for all graph writes.
            Organization::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $currentOrganization = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $currentParent = $parent === null ? null : Organization::query()->whereKey($parent->getKey())->lockForUpdate()->firstOrFail();
            if ($currentOrganization->status !== OrganizationStatus::Active || ($currentParent !== null && $currentParent->status !== OrganizationStatus::Active)) {
                throw ValidationException::withMessages(['organization' => __('organization.validation.move_requires_active_units')]);
            }

            $seen = [$currentOrganization->id => true];
            $ancestor = $currentParent?->id;
            while ($ancestor !== null) {
                if (isset($seen[$ancestor])) {
                    throw ValidationException::withMessages(['parent' => __('organization.validation.move_creates_cycle')]);
                }
                $seen[$ancestor] = true;
                $ancestor = OrganizationParent::query()->where('organization_id', $ancestor)->whereNull('valid_to')->lockForUpdate()->first()?->parent_id;
            }

            $previous = OrganizationParent::query()->where('organization_id', $organization->id)->whereNull('valid_to')->lockForUpdate()->first();
            // Central control (E3.10a1), after the read-only checks so a refused move writes nothing: structure.manage
            // over the unit, over its current parent and over the new parent — nobody moves a unit out of, or into,
            // a place they do not manage.
            $this->access->authorizeAll(Permission::StructureManage, array_values(array_filter([$currentOrganization, $previous?->parent, $currentParent])));
            if ($previous?->parent_id === $currentParent?->id) {
                return $previous;
            }
            $at = CarbonImmutable::now('UTC');
            $previous?->end($at);

            return $currentParent === null ? null : OrganizationParent::startPeriod([
                'organization_id' => $currentOrganization->id,
                'parent_id' => $currentParent->id,
            ], $at);
        }, attempts: 3)));
    }
}
