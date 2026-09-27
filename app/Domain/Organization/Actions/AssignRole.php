<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Organization\OrganizationHierarchy;
use App\Domain\Platform\AuditReason;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives an account a role in a SCOPE from now, optionally until a moment (expiry). The scope must be the
 * role's organization or a unit below it; the role and the scope must be active. The inheritance policy
 * is always explicit. Who may assign (roles.assign) is checked by the calling flow (E3.6+).
 */
final class AssignRole
{
    public function __construct(private readonly AuditReason $reason, private readonly OrganizationHierarchy $hierarchy) {}

    public function handle(User $account, AccessRole $role, Organization $scope, ScopeInheritance $inheritance, ?DateTimeInterface $until, string $reason): RoleAssignment
    {
        $now = CarbonImmutable::now('UTC');
        if ($until !== null && CarbonImmutable::instance($until)->lessThanOrEqualTo($now)) {
            throw ValidationException::withMessages(['until' => 'Termin wygaśnięcia musi być w przyszłości.']);
        }

        return $this->reason->because($reason, fn () => DB::transaction(function () use ($account, $role, $scope, $inheritance, $until, $now): RoleAssignment {
            User::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $currentRole = AccessRole::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $currentScope = Organization::query()->whereKey($scope->getKey())->lockForUpdate()->firstOrFail();
            if ($currentRole->status !== AccessRoleStatus::Active) {
                throw ValidationException::withMessages(['role' => 'Nie można nadać wycofanej roli.']);
            }
            if ($currentScope->status !== OrganizationStatus::Active) {
                throw ValidationException::withMessages(['scope' => 'Zakres musi być aktywną jednostką.']);
            }
            $roleOwner = $currentRole->organization_id;
            if ($currentScope->id !== $roleOwner && ! $this->hierarchy->ancestorsAt($currentScope, $now)->contains('id', $roleOwner)) {
                throw ValidationException::withMessages(['scope' => 'Rolę można nadać tylko w organizacji, która ją zdefiniowała, albo w jej jednostkach.']);
            }

            $assignment = RoleAssignment::startPeriod([
                'user_id' => $account->getKey(),
                'access_role_id' => $currentRole->id,
                'scope_organization_id' => $currentScope->id,
                'scope_inheritance' => $inheritance,
            ], $now);

            return $until === null ? $assignment : $assignment->end($until);
        }, attempts: 3));
    }
}
