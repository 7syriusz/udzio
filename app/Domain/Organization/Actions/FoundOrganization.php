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
use App\Domain\Platform\Actions\RunIdempotently;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Founding an organization (E3.10a, Z-043) — a basic function of UDZIO for any account with a verified e-mail.
 * In one transaction: the organization, the founder role defined by `organization.founding.founder_role` and its
 * assignment to the founder over the organization and its units. The founder gets rights in this organization
 * only — never platform rights — and, as the role is privileged, uses them only with MFA (Z-038). A technical
 * process or an anonymous request founds nothing. Audited as `organization.founded`.
 *
 * Protection against automated founding (E3.10a1, Z-043): every attempt counts against
 * `organization.founding.attempts_per_hour` per account; the form's request key makes a repeated submission
 * return the organization already founded (RunIdempotently, also for concurrent duplicates); attempts of one
 * account are serialized on its row; an optional `max_per_account` (null — no limit yet) can be set later. Refused
 * attempts are audited as denials.
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
        private readonly RunIdempotently $idempotently,
    ) {}

    /** @param string $requestKey key of the submitted form (8–191 printable characters), the same for a repeated submission */
    public function handle(string $name, string $reason, string $requestKey): Organization
    {
        $actor = $this->context->current();
        $founder = $actor->type === ActorType::Account ? User::query()->find($actor->identifier) : null;
        if ($founder === null || $founder->email_verified_at === null) {
            $this->refuse($founder, $founder === null ? AccessDecision::REASON_NO_ACCOUNT : PrivilegedAccessPolicy::REASON_EMAIL_UNVERIFIED);
        }
        $limiter = 'organization-founding:'.$founder->id;
        if (RateLimiter::tooManyAttempts($limiter, (int) config('organization.founding.attempts_per_hour'))) {
            $this->recordRefusal($founder, 'founding_rate_limited');

            throw new ThrottleRequestsException('organization.founding.too_many_attempts', headers: ['Retry-After' => RateLimiter::availableIn($limiter)]);
        }
        RateLimiter::hit($limiter, 3600);
        $name = OrganizationName::validate($name);

        $outcome = $this->idempotently->handle('organization.found', $requestKey, ['name' => $name], fn () => $this->found($founder, $name, $reason)->public_id);

        return Organization::query()->where('public_id', $outcome->value)->firstOrFail();
    }

    private function found(User $founder, string $name, string $reason): Organization
    {
        return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($name, $founder): Organization {
            // Attempts of one account are serialized, so a configured limit cannot be passed by parallel requests.
            User::query()->whereKey($founder->id)->lockForUpdate()->firstOrFail();
            $limit = config('organization.founding.max_per_account');
            if ($limit !== null && AuditEntry::query()->where('action', 'organization.founded')->where('actor_type', ActorType::Account->value)->where('actor_id', (string) $founder->id)->count() >= (int) $limit) {
                $this->refuse($founder, 'founding_limit_reached');
            }
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

    private function refuse(?User $founder, string $reason): never
    {
        $this->recordRefusal($founder, $reason);

        throw (new AccessDenied('organization', 'new', null, 'organization.found', AccessDenied::messageFor($reason)))->alreadyRecorded();
    }

    private function recordRefusal(?User $founder, string $reason): void
    {
        $this->decider->recordDenial('organization', 'new', null, ['permission' => 'organization.found', 'account_id' => $founder?->getKey(), 'decision' => 'denied', 'reason' => $reason]);
    }
}
