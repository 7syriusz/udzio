<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\AccessDecision;
use App\Domain\Organization\Access\PrivilegedAccessPolicy;
use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Founding an organization (E3.10a, Z-043) — a basic function of UDZIO for any account with a verified e-mail.
 * In one transaction: the organization, the founder role defined by `organization.founding.founder_role` and its
 * assignment to the founder over the organization and its units. The founder gets rights in this organization
 * only — never platform rights — and, as the role is privileged, uses them only with MFA (Z-038). A technical
 * process or an anonymous request founds nothing. Audited as `organization.founded`.
 */
final class FoundOrganization
{
    public function __construct(
        private readonly CreateOrganization $create,
        private readonly AccessDecider $decider,
        private readonly ActorContext $context,
        private readonly AuditReason $reason,
        private readonly OperationCorrelation $operation,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(string $name, string $reason): Organization
    {
        $actor = $this->context->current();
        $founder = $actor->type === ActorType::Account ? User::query()->find($actor->identifier) : null;
        if ($founder === null || $founder->email_verified_at === null) {
            $denial = $founder === null ? AccessDecision::REASON_NO_ACCOUNT : PrivilegedAccessPolicy::REASON_EMAIL_UNVERIFIED;
            $this->decider->recordDenial('organization', 'new', null, ['permission' => 'organization.found', 'account_id' => $founder?->getKey(), 'decision' => 'denied', 'reason' => $denial]);

            throw (new AccessDenied('organization', 'new', null, 'organization.found', AccessDenied::messageFor($denial)))->alreadyRecorded();
        }

        return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($name, $founder): Organization {
            $organization = $this->create->handle($name);
            $template = config('organization.founding.founder_role');
            $permissions = AccessRoleRules::permissions($template['permissions']);
            $role = AccessRole::query()->create([
                'organization_id' => $organization->id,
                'name' => AccessRoleRules::name($template['name']),
                'permissions' => $permissions,
                'grant_rules' => AccessRoleRules::grantRules($template['grant_rules'] ?? [], $organization, $permissions),
                'requires_mfa' => (bool) ($template['requires_mfa'] ?? true),
                'status' => AccessRoleStatus::Active,
            ]);
            $role->publishVersion();
            $assignment = RoleAssignment::startPeriod([
                'user_id' => $founder->id,
                'access_role_id' => $role->id,
                'scope_organization_id' => $organization->id,
                'scope_inheritance' => ScopeInheritance::UnitAndDescendants,
            ], CarbonImmutable::now('UTC'));
            $this->audit->handle('organization.founded', 'organization', $organization->public_id, organizationId: $organization->public_id, after: [
                'founder_account_id' => $founder->id,
                'founder_role' => $role->public_id,
                'assignment' => $assignment->public_id,
            ]);

            return $organization;
        })));
    }
}
