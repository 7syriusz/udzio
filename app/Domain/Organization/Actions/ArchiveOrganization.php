<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\PrivilegedAccessPolicy;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Organization\OrganizationHierarchy;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Archives an organization or a unit together with all its active sub-units (E3.10e, Z-047) without deleting
 * anything — history, audit, role assignments and every unit's position at that moment stay (E3.10a1, Z-043). An
 * active unit is never left below an archived one. Every archived unit gets its own audited change, with the same
 * moment and reason.
 * - A unit: `structure.manage` over the unit and over its parent.
 * - A whole organization (a root): `organization.manage`, and for a person also confirmed MFA and an explicit
 *   confirmation — the organization's name typed again; the reason is always required.
 */
final class ArchiveOrganization
{
    public function __construct(
        private readonly AuditReason $reason,
        private readonly AccessDecider $access,
        private readonly OperationCorrelation $operation,
        private readonly ActorContext $context,
        private readonly OrganizationHierarchy $hierarchy,
    ) {}

    /** @param string|null $confirmation the organization's name typed again (required from a person for a whole organization) */
    public function handle(Organization $organization, string $reason, ?string $confirmation = null): Organization
    {
        $reason = Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:1000']])->validate()['reason'];

        return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($organization, $confirmation): Organization {
            $current = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            if ($current->status === OrganizationStatus::Archived) {
                return $current;
            }
            $now = CarbonImmutable::now('UTC');
            $parent = OrganizationParent::query()->where('organization_id', $current->id)->activeAt($now)->first()?->parent;
            if ($parent === null) {
                $this->authorizeWholeOrganization($current, $confirmation);
            } else {
                $this->access->authorizeAll(Permission::StructureManage, [$current, $parent]);
            }

            $subtree = $this->hierarchy->descendantsAt($current, $now)->filter(fn (Organization $unit) => $unit->status === OrganizationStatus::Active);
            foreach ([$current, ...$subtree->all()] as $unit) {
                $locked = Organization::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();
                $locked->status = OrganizationStatus::Archived;
                $locked->archived_at = $now;
                $locked->save();
            }

            return $current->fresh();
        })));
    }

    private function authorizeWholeOrganization(Organization $organization, ?string $confirmation): void
    {
        $this->access->authorize(Permission::OrganizationManage, $organization);
        $actor = $this->context->current();
        if ($actor->type !== ActorType::Account) {
            return; // a technical process is bound by its declared purpose (SystemAuthority)
        }
        $account = User::query()->findOrFail($actor->identifier);
        $refusal = match (true) {
            ! $account->hasConfirmedTwoFactor() => PrivilegedAccessPolicy::REASON_MFA_REQUIRED,
            $confirmation === null || trim($confirmation) !== $organization->name => 'confirmation_missing',
            default => null,
        };
        if ($refusal !== null) {
            $this->access->recordDenial('organization', $organization->public_id, $organization->public_id, [
                'permission' => Permission::OrganizationManage->value, 'operation' => 'archive', 'account_id' => $account->id, 'decision' => 'denied', 'reason' => $refusal,
            ]);

            throw (new AccessDenied('organization', $organization->public_id, $organization->public_id, Permission::OrganizationManage->value, AccessDenied::messageFor($refusal)))->alreadyRecorded();
        }
    }
}
