<?php

namespace App\Domain\Organization\Access;

use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actions\RecordAccessDenial;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\DefinitionVersion;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
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
        private readonly OperationCorrelation $correlation,
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
     * Decision on assigning, approving or revoking `$role` in `$scope` by `$account` (E3.6a). Allowed when an
     * active assignment of the account holds `roles.assign`, covers `$scope` (its own inheritance policy) and
     * its role version lists `$role` in the role-granting catalog with a rule that allows this unit and
     * duration. Kept rules: no self-assignment, no approval of one's own request, and no handing out more
     * role-granting power than one has (indirect self-escalation). Operational permissions of the granted
     * role do not have to be held by the granting account.
     *
     * @param  'assign'|'approve'|'revoke'  $operation
     */
    public function decideRoleGrant(?User $account, string $operation, AccessRole $role, Organization $scope, ?DateTimeInterface $until = null, ?int $granteeAccountId = null, ?string $requestedByAccountId = null, ?DateTimeInterface $at = null): AccessDecision
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now('UTC'))->utc();
        $until = $until === null ? null : CarbonImmutable::instance($until)->utc();
        $basis = [
            'permission' => Permission::RolesAssign->value,
            'operation' => $operation,
            'at' => $at->format('Y-m-d H:i:s.u'),
            'account_id' => $account?->getKey(),
            'target_organization' => $scope->public_id,
            'granted_role' => $role->public_id,
            'grantee_account_id' => $granteeAccountId,
            'until' => $until?->format('Y-m-d H:i:s.u'),
        ];
        if ($account === null) {
            return $this->deny(AccessDecision::REASON_NO_ACCOUNT, $basis);
        }
        $scope = Organization::query()->findOrFail($scope->getKey());
        if (! $scope->isActiveAt($at)) {
            return $this->deny(AccessDecision::REASON_TARGET_INACTIVE, $basis);
        }
        if ($operation !== 'revoke' && $granteeAccountId === $account->getKey()) {
            return $this->deny('self_assignment', $basis);
        }
        if ($operation === 'approve' && $requestedByAccountId === (string) $account->getKey()) {
            return $this->deny('approval_by_requester', $basis);
        }

        $assignments = RoleAssignment::query()->where('user_id', $account->getKey())->activeAt($at)
            ->with(['role.organization', 'scopeOrganization'])->orderBy('id')->get();
        if ($assignments->isEmpty()) {
            return $this->deny(AccessDecision::REASON_NO_ACTIVE_ASSIGNMENT, $basis);
        }

        $considered = [];
        $match = null;
        $catalog = [];
        foreach ($assignments as $assignment) {
            [$outcome, $version] = $this->assignmentOutcome($assignment, Permission::RolesAssign, $at);
            if ($outcome === 'applicable') {
                $path = $this->structurePath($scope, $assignment->scopeOrganization, $at);
                $outcome = $path === null || ($path !== [] && $assignment->scope_inheritance !== ScopeInheritance::UnitAndDescendants)
                    ? 'scope_not_covering' : 'covering';
            }
            if ($outcome !== 'covering') {
                $considered[] = ['assignment' => $assignment->public_id, 'outcome' => $outcome];

                continue;
            }
            $rules = collect($version->content['grant_rules'] ?? [])->keyBy('role');
            $catalog = [...$catalog, ...$rules->filter(fn (array $rule) => $path === [] || $rule['include_descendants'])->keys()->all()];
            $rule = $rules->get($role->public_id);
            $outcome = match (true) {
                $rule === null => 'role_not_in_catalog',
                $path !== [] && ! $rule['include_descendants'] => 'scope_not_covering',
                $operation === 'assign' && $rule['max_days'] !== null && $until === null => 'duration_required',
                $operation === 'assign' && $rule['max_days'] !== null && $until->greaterThan($at->addDays($rule['max_days'])) => 'duration_exceeds_limit',
                default => 'match',
            };
            $considered[] = ['assignment' => $assignment->public_id, 'outcome' => $outcome];
            if ($outcome === 'match' && $match === null) {
                $match = [
                    'assignment' => $assignment->public_id,
                    'role' => $assignment->role->public_id,
                    'role_version' => $version->version,
                    'scope_organization' => $assignment->scopeOrganization->public_id,
                    'scope_inheritance' => $assignment->scope_inheritance->value,
                    'structure_path' => $path,
                    'grant_rule' => $rule,
                    'requires_approval' => $operation === 'assign' && $rule['requires_approval'],
                ];
            }
        }
        if ($match === null) {
            return $this->deny(AccessDecision::REASON_NO_MATCHING_ASSIGNMENT, [...$basis, 'considered' => $considered]);
        }

        if ($operation !== 'revoke') {
            $escalation = $this->grantingPowerBeyondOwn($role, $account, $scope, array_values(array_unique($catalog)), $at);
            if ($escalation !== []) {
                return $this->deny('delegation_power_exceeds_own', [...$basis, 'exceeding' => $escalation]);
            }
        }

        return new AccessDecision(true, AccessDecision::REASON_ASSIGNMENT, [...$basis, ...$match, 'decision' => 'allowed', 'reason' => AccessDecision::REASON_ASSIGNMENT]);
    }

    /**
     * Records and enforces decideRoleGrant for the current ACTOR (SystemAuthority for a technical process).
     *
     * @param  'assign'|'approve'|'revoke'  $operation
     */
    public function authorizeRoleGrant(string $operation, AccessRole $role, Organization $scope, ?DateTimeInterface $until, ?int $granteeAccountId, ?string $requestedByAccountId = null, ?string $subjectId = null): AccessDecision
    {
        $systemDecision = $this->systemDecision(Permission::RolesAssign, $scope,
            fn (User $orderedBy) => $this->decideRoleGrant($orderedBy, $operation, $role, $scope, $until, $granteeAccountId, $requestedByAccountId));
        if ($systemDecision !== null) {
            return $this->record($systemDecision, Permission::RolesAssign, $scope, 'role_assignment', $subjectId);
        }
        $actor = $this->context->current();
        $account = $actor->type === ActorType::Account ? User::query()->find($actor->identifier) : null;

        return $this->record(
            $this->decideRoleGrant($account, $operation, $role, $scope, $until, $granteeAccountId, $requestedByAccountId),
            Permission::RolesAssign, $scope, 'role_assignment', $subjectId,
        );
    }

    /**
     * Defining or changing roles (`roles.manage`). An account cannot change a role it holds itself —
     * that would raise its own permissions indirectly.
     */
    public function authorizeRoleDefinition(Organization $organization, ?AccessRole $existing = null): AccessDecision
    {
        $decision = $this->decideForActor(Permission::RolesManage, $organization);
        $actor = $this->context->current();
        if ($decision->allowed && $existing !== null && $actor->type === ActorType::Account
            && RoleAssignment::query()->where('user_id', $actor->identifier)->where('access_role_id', $existing->id)->activeAt(CarbonImmutable::now('UTC'))->exists()) {
            $decision = new AccessDecision(false, 'modifies_own_role', [...$decision->basis, 'decision' => 'denied', 'reason' => 'modifies_own_role', 'role' => $existing->public_id]);
        }

        return $this->record($decision, Permission::RolesManage, $organization, 'access_role', $existing?->public_id);
    }

    /**
     * Role-granting power the granted role would carry beyond the granting account's own: role-management
     * permissions it does not hold here, or catalog entries outside its own catalog for this unit.
     *
     * @param  list<string>  $ownCatalog
     * @return list<string>
     */
    private function grantingPowerBeyondOwn(AccessRole $role, User $account, Organization $scope, array $ownCatalog, CarbonImmutable $at): array
    {
        $version = $role->versionAt($at);
        $exceeding = [];
        foreach ([Permission::RolesAssign, Permission::RolesManage] as $power) {
            if (in_array($power->value, $version->content['permissions'], true) && $this->decide($account, $power, $scope, $at)->denied()) {
                $exceeding[] = $power->value;
            }
        }
        foreach ($version->content['grant_rules'] ?? [] as $rule) {
            if (! in_array($rule['role'], $ownCatalog, true)) {
                $exceeding[] = 'catalog:'.$rule['role'];
            }
        }

        return $exceeding;
    }

    /**
     * Decision for a technical process under SystemAuthority (E3.7b): only within its declared purpose —
     * permission, scope, and the current permission of the account that ordered it. Null when the actor is
     * not a process with a purpose.
     *
     * @param  (Closure(User): AccessDecision)|null  $orderedByDecision  how to decide for the ordering account
     */
    private function systemDecision(Permission $permission, Organization $target, ?Closure $orderedByDecision = null): ?AccessDecision
    {
        $purpose = $this->system->activePurpose();
        if ($this->context->current()->type === ActorType::Account || $purpose === null) {
            return null;
        }
        $now = CarbonImmutable::now('UTC');
        $target = Organization::query()->findOrFail($target->getKey());
        $basis = [
            'permission' => $permission->value,
            'at' => $now->format('Y-m-d H:i:s.u'),
            'target_organization' => $target->public_id,
            'system_purpose' => $purpose->describe(),
        ];
        if (! $purpose->allows($permission)) {
            return $this->deny('system_purpose_permission_missing', $basis);
        }
        if (! $purpose->covers($target, $now)) {
            return $this->deny('system_purpose_scope_not_covering', $basis);
        }
        if ($purpose->onBehalfOf !== null) {
            $ordered = $orderedByDecision !== null ? $orderedByDecision($purpose->onBehalfOf) : $this->decide($purpose->onBehalfOf, $permission, $target, $now);
            if ($ordered->denied()) {
                return $this->deny('ordering_account_not_permitted', [...$basis, 'ordering_account_decision' => $ordered->basis]);
            }
        }

        return new AccessDecision(true, AccessDecision::REASON_SYSTEM_AUTHORITY, [...$basis, 'decision' => 'allowed', 'reason' => AccessDecision::REASON_SYSTEM_AUTHORITY]);
    }

    private function decideForActor(Permission $permission, Organization $target): AccessDecision
    {
        $systemDecision = $this->systemDecision($permission, $target);
        if ($systemDecision !== null) {
            return $systemDecision;
        }
        $actor = $this->context->current();
        $account = $actor->type === ActorType::Account ? User::query()->find($actor->identifier) : null;

        return $this->decide($account, $permission, $target);
    }

    private function record(AccessDecision $decision, Permission $permission, Organization $target, string $subjectType, ?string $subjectId): AccessDecision
    {
        $subjectId ??= $target->public_id;
        if ($decision->allowed) {
            // One final decision entry per protected operation, however many checks it performs.
            if ($this->correlation->firstTime(implode('|', ['granted', $permission->value, $target->public_id, $subjectType, $subjectId]))) {
                $this->audit->handle('access.granted', $subjectType, $subjectId, organizationId: $target->public_id, after: $decision->basis);
            }

            return $decision;
        }

        $this->recordDenial($subjectType, $subjectId, $target->public_id, $decision->basis);

        throw (new AccessDenied($subjectType, $subjectId, $target->public_id, $permission->value))->alreadyRecorded();
    }

    /**
     * The one way a denial is written (decisions here and reads refused by DataVisibility): once per
     * operation and subject, on the independent audit connection; a failed write never changes the denial.
     *
     * @param  array<string, mixed>  $basis
     */
    public function recordDenial(string $subjectType, string $subjectId, ?string $organizationId, array $basis): void
    {
        if (! $this->correlation->firstTime(implode('|', ['denied', $basis['permission'] ?? $basis['ability'] ?? '', $subjectType, $subjectId]))) {
            return;
        }
        try {
            $this->denials->handle($subjectType, $subjectId, $organizationId, $basis);
        } catch (Throwable $failure) {
            // Same rule as E1.4: a failed audit write never turns a denial into something else.
            Log::critical('Access denial could not be audited.', ['exception' => $failure::class, 'message' => $failure->getMessage()]);
        }
    }

    /**
     * Internal IDs of the organizations where the account holds `$permission` at the moment — the same rules
     * as decide(), evaluated for all targets at once (data isolation, E3.7).
     *
     * @return list<int>
     */
    public function grantedOrganizationIds(User $account, Permission $permission, ?DateTimeInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now('UTC'))->utc();
        $ids = [];
        $assignments = RoleAssignment::query()->where('user_id', $account->getKey())->activeAt($at)
            ->with(['role.organization', 'scopeOrganization'])->orderBy('id')->get();
        foreach ($assignments as $assignment) {
            if ($this->assignmentOutcome($assignment, $permission, $at)[0] === 'applicable') {
                $ids = [...$ids, ...$assignment->coveredOrganizationIds($at)];
            }
        }
        $ids = array_values(array_unique($ids));

        return Organization::query()->whereKey($ids)->get()
            ->filter(fn (Organization $organization) => $organization->isActiveAt($at))
            ->modelKeys();
    }

    /**
     * Units whose history the account may view with `$permission` (E3.7b). Only assignments active NOW count —
     * a role held in the past gives nothing today. Each such assignment covers, for a past moment, the units
     * that were under its scope unit at that moment (so a later move does not rewrite where a unit was); for
     * the present, the units it covers today plus archived units that belonged to it when they were archived.
     * This is the user's right to view history, separate from reconstructing a past decision (decide()).
     *
     * @return list<int>
     */
    public function historyOrganizationIds(User $account, Permission $permission, ?DateTimeInterface $at = null): array
    {
        $now = CarbonImmutable::now('UTC');
        $assignments = RoleAssignment::query()->where('user_id', $account->getKey())->activeAt($now)
            ->with(['role.organization', 'scopeOrganization'])->orderBy('id')->get()
            ->filter(fn (RoleAssignment $assignment) => $this->assignmentOutcome($assignment, $permission, $now)[0] === 'applicable');
        if ($assignments->isEmpty()) {
            return [];
        }
        if ($at !== null) {
            $at = CarbonImmutable::instance($at)->utc();

            return array_values(array_unique(array_merge(...$assignments->map(fn (RoleAssignment $assignment) => $assignment->coveredOrganizationIds($at))->values()->all())));
        }
        $ids = $this->grantedOrganizationIds($account, $permission, $now);
        foreach (Organization::query()->whereNotNull('archived_at')->whereKeyNot($ids)->get() as $archived) {
            $lastActive = $archived->archived_at->subMicrosecond();
            foreach ($assignments as $assignment) {
                $path = $this->structurePath($archived, $assignment->scopeOrganization, $lastActive);
                if ($path === [] || ($path !== null && $assignment->scope_inheritance === ScopeInheritance::UnitAndDescendants)) {
                    $ids[] = $archived->id;

                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Role-granting catalog the account can use at the moment (E3.6a): for each role, the units where the
     * account may assign or revoke it — the same rules as decideRoleGrant (without the per-operation checks
     * of self-assignment, duration and escalation). Used to limit what role data a manager may see (E3.7a).
     *
     * @return array<string, list<int>> role public ID => internal IDs of units
     */
    public function roleGrantCatalog(User $account, ?DateTimeInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now('UTC'))->utc();
        $catalog = [];
        $assignments = RoleAssignment::query()->where('user_id', $account->getKey())->activeAt($at)
            ->with(['role.organization', 'scopeOrganization'])->orderBy('id')->get();
        foreach ($assignments as $assignment) {
            [$outcome, $version] = $this->assignmentOutcome($assignment, Permission::RolesAssign, $at);
            if ($outcome !== 'applicable') {
                continue;
            }
            foreach ($version->content['grant_rules'] ?? [] as $rule) {
                $units = $rule['include_descendants'] ? $assignment->coveredOrganizationIds($at) : [$assignment->scope_organization_id];
                $catalog[$rule['role']] = [...($catalog[$rule['role']] ?? []), ...$units];
            }
        }
        $active = Organization::query()->whereKey(array_merge([], ...array_values($catalog)))->get()
            ->filter(fn (Organization $organization) => $organization->isActiveAt($at))->modelKeys();

        return array_map(fn (array $units) => array_values(array_intersect(array_unique($units), $active)), $catalog);
    }

    /**
     * Whether the assignment can grant `$permission` at all at the moment, regardless of the target.
     *
     * @return array{0: string, 1: ?DefinitionVersion}
     */
    private function assignmentOutcome(RoleAssignment $assignment, Permission $permission, CarbonImmutable $at): array
    {
        $version = $assignment->role->versionAt($at);
        if ($version === null || $version->content['status'] !== AccessRoleStatus::Active->value) {
            return ['role_inactive', null];
        }
        if (! in_array($permission->value, $version->content['permissions'], true)) {
            return ['permission_missing', null];
        }
        if (! $assignment->role->organization->isActiveAt($at)) {
            return ['role_organization_inactive', null];
        }
        if (! $assignment->scopeOrganization->isActiveAt($at)) {
            return ['scope_inactive', null];
        }

        return ['applicable', $version];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function evaluate(RoleAssignment $assignment, Permission $permission, Organization $target, CarbonImmutable $at): array
    {
        [$outcome, $roleVersion] = $this->assignmentOutcome($assignment, $permission, $at);
        $roleVersion = $roleVersion?->version;
        if ($outcome !== 'applicable') {
            return [$outcome, []];
        }
        $scope = $assignment->scopeOrganization;
        $path = $this->structurePath($target, $scope, $at);
        if ($path === null || ($path !== [] && $assignment->scope_inheritance !== ScopeInheritance::UnitAndDescendants)) {
            return ['scope_not_covering', []];
        }

        return ['match', [
            'assignment' => $assignment->public_id,
            'role' => $assignment->role->public_id,
            'role_version' => $roleVersion,
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
