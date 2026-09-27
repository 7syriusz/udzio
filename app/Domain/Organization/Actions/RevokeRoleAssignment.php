<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ends an assignment now; history stays. An assignment already ended (or expired) is left as it is.
 * The current ACTOR must be allowed to grant this role in this scope by its role-granting catalog
 * (AccessDecider::authorizeRoleGrant, E3.6a). Revoking a pending assignment rejects the request.
 */
final class RevokeRoleAssignment
{
    public function __construct(
        private readonly AuditReason $reason,
        private readonly AccessDecider $access,
        private readonly OperationCorrelation $operation,
    ) {}

    public function handle(RoleAssignment $assignment, string $reason): RoleAssignment
    {
        return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($assignment): RoleAssignment {
            $current = RoleAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorizeRoleGrant('revoke', $current->role, $current->scopeOrganization, null, $current->user_id, subjectId: $current->public_id);
            $now = CarbonImmutable::now('UTC');
            if ($current->valid_to !== null && $current->valid_to->lessThanOrEqualTo($now)) {
                return $current;
            }
            if ($current->valid_to === null) {
                return $current->end($now);
            }

            return $current->shortenScheduledEnd($now);
        })));
    }
}
