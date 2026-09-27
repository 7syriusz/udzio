<?php

namespace App\Domain\Organization\Access;

use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actions\RecordAccessDenial;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The single place of access decisions (A5 §2.4, A5-14): ACCOUNT + active role assignment + PERMISSION +
 * SCOPE/CONTEXT + activity of the organizations + structure valid at the moment. Works for any moment:
 * assignments and structure are periods, roles are versioned and archiving is time-stamped, so a past
 * decision is reconstructed by deciding again at that moment. ACCESS ROLEs only — RELATION ROLEs
 * (membership, representation) never grant access here.
 */
final class AccessDecider
{
    public function __construct(
        private readonly ActorContext $context,
        private readonly SystemAuthority $system,
        private readonly RecordAudit $audit,
        private readonly RecordAccessDenial $denials,
    ) {}

    public function decide(?User $account, Permission $permission, Organization $target, ?DateTimeInterface $at = null): AccessDecision
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now('UTC'))->utc();
        $basis = [
            'permission' => $permission->value,
            'at' => $at->format('Y-m-d H:i:s.u'),
            'account_id' => $account?->getKey(),
            'target_organization' => $target->public_id,
        ];
        if ($account === null) {
            return $this->deny(AccessDecision::REASON_NO_ACCOUNT, $basis);
        }
        // Decide on the stored state, never on a possibly stale in-memory model.
        $target = Organization::query()->findOrFail($target->getKey());
        if (! $target->isActiveAt($at)) {
            return $this->deny(AccessDecision::REASON_TARGET_INACTIVE, $basis);
        }

        $assignments = RoleAssignment::query()->where('user_id', $account->getKey())->activeAt($at)
            ->with(['role.organization', 'scopeOrganization'])->orderBy('id')->get();
        if ($assignments->isEmpty()) {
            return $this->deny(AccessDecision::REASON_NO_ACTIVE_ASSIGNMENT, $basis);
        }

        $considered = [];
        foreach ($assignments as $assignment) {
            [$outcome, $details] = $this->evaluate($assignment, $permission, $target, $at);
            if ($outcome === 'match') {
                return new AccessDecision(true, AccessDecision::REASON_ASSIGNMENT, [...$basis, ...$details, 'decision' => 'allowed', 'reason' => AccessDecision::REASON_ASSIGNMENT]);
            }
            $considered[] = ['assignment' => $assignment->public_id, 'outcome' => $outcome];
        }

        return $this->deny(AccessDecision::REASON_NO_MATCHING_ASSIGNMENT, [...$basis, 'considered' => $considered]);
    }

    /**
     * Decision for the current ACTOR, recorded in the audit: a grant as `access.granted` (inside the
     * caller's transaction), a denial as `access.denied` (independent connection) followed by AccessDenied.
     * Every path — screen, API, command, automation — must call this before a protected operation.
     */
    public function authorize(Permission $permission, Organization $target, string $subjectType = 'organization', ?string $subjectId = null): AccessDecision
    {
        return $this->record($this->decideForActor($permission, $target), $permission, $target, $subjectType, $subjectId);
    }

    /**
     * `authorize()` plus the delegation rules for role management: an account cannot put into a role or
     * hand to someone permissions it does not hold itself in that scope, and cannot give a role to itself.
     * The final outcome is decided first and recorded once.
     *
     * @param  list<string>  $delegated  permissions that the operation would grant
     */
    public function authorizeDelegation(Permission $permission, Organization $target, array $delegated, ?int $granteeAccountId, string $subjectType, ?string $subjectId = null): AccessDecision
    {
        $decision = $this->decideForActor($permission, $target);
        if ($decision->allowed && $decision->reason !== AccessDecision::REASON_SYSTEM_AUTHORITY) {
            $account = User::query()->findOrFail($this->context->current()->identifier);
            $missing = array_values(array_diff($delegated, $this->permissionsHeld($account, $target)));
            $selfGrant = $granteeAccountId !== null && $granteeAccountId === $account->getKey();
            if ($missing !== [] || $selfGrant) {
                $reason = $selfGrant ? 'self_assignment' : 'delegation_exceeds_own_permissions';
                $decision = new AccessDecision(false, $reason, [...$decision->basis, 'decision' => 'denied', 'reason' => $reason, 'missing' => $missing]);
            }
        }

        return $this->record($decision, $permission, $target, $subjectType, $subjectId);
    }

    private function decideForActor(Permission $permission, Organization $target): AccessDecision
    {
        $actor = $this->context->current();
        $systemReason = $this->system->activeReason();
        if ($actor->type !== ActorType::Account && $systemReason !== null) {
            return new AccessDecision(true, AccessDecision::REASON_SYSTEM_AUTHORITY, [
                'permission' => $permission->value,
                'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'target_organization' => $target->public_id,
                'decision' => 'allowed',
                'reason' => AccessDecision::REASON_SYSTEM_AUTHORITY,
                'system_reason' => $systemReason,
            ]);
        }
        $account = $actor->type === ActorType::Account ? User::query()->find($actor->identifier) : null;

        return $this->decide($account, $permission, $target);
    }

    private function record(AccessDecision $decision, Permission $permission, Organization $target, string $subjectType, ?string $subjectId): AccessDecision
    {
        $subjectId ??= $target->public_id;
        if ($decision->allowed) {
            $this->audit->handle('access.granted', $subjectType, $subjectId, organizationId: $target->public_id, after: $decision->basis);

            return $decision;
        }
        try {
            $this->denials->handle($subjectType, $subjectId, $target->public_id, $decision->basis);
        } catch (Throwable $failure) {
            // Same rule as E1.4: a failed audit write never turns a denial into something else.
            Log::critical('Access denial could not be audited.', ['exception' => $failure::class, 'message' => $failure->getMessage()]);
        }

        throw (new AccessDenied($subjectType, $subjectId, $target->public_id, $permission->value))->alreadyRecorded();
    }

    /**
     * Permissions the account holds in `$target` at the moment, across all its matching assignments.
     *
     * @return list<string>
     */
    public function permissionsHeld(User $account, Organization $target, ?DateTimeInterface $at = null): array
    {
        return array_values(array_filter(
            array_map(fn (Permission $p) => $p->value, Permission::cases()),
            fn (string $p) => $this->decide($account, Permission::from($p), $target, $at)->allowed,
        ));
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function evaluate(RoleAssignment $assignment, Permission $permission, Organization $target, CarbonImmutable $at): array
    {
        $role = $assignment->role;
        $version = $role->versionAt($at);
        if ($version === null || $version->content['status'] !== AccessRoleStatus::Active->value) {
            return ['role_inactive', []];
        }
        if (! in_array($permission->value, $version->content['permissions'], true)) {
            return ['permission_missing', []];
        }
        if (! $role->organization->isActiveAt($at)) {
            return ['role_organization_inactive', []];
        }
        $scope = $assignment->scopeOrganization;
        if (! $scope->isActiveAt($at)) {
            return ['scope_inactive', []];
        }
        $path = $this->structurePath($target, $scope, $at);
        if ($path === null || ($path !== [] && $assignment->scope_inheritance !== ScopeInheritance::UnitAndDescendants)) {
            return ['scope_not_covering', []];
        }

        return ['match', [
            'assignment' => $assignment->public_id,
            'role' => $role->public_id,
            'role_version' => $version->version,
            'scope_organization' => $scope->public_id,
            'scope_inheritance' => $assignment->scope_inheritance->value,
            'structure_path' => $path,
        ]];
    }

    /**
     * Parent periods leading from `$target` up to `$scope` at the moment: [] when target is the scope,
     * null when the scope is not above the target.
     *
     * @return list<string>|null
     */
    private function structurePath(Organization $target, Organization $scope, CarbonImmutable $at): ?array
    {
        $path = [];
        $seen = [];
        $id = $target->id;
        while ($id !== $scope->id) {
            if (isset($seen[$id])) {
                throw new LogicException('Corrupt organization hierarchy: cycle detected.');
            }
            $seen[$id] = true;
            $period = OrganizationParent::query()->where('organization_id', $id)->activeAt($at)->first();
            if ($period === null) {
                return null;
            }
            $path[] = $period->public_id;
            $id = $period->parent_id;
        }

        return $path;
    }

    /** @param array<string, mixed> $basis */
    private function deny(string $reason, array $basis): AccessDecision
    {
        return new AccessDecision(false, $reason, [...$basis, 'decision' => 'denied', 'reason' => $reason]);
    }
}
