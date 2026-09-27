<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\AuditReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ends an assignment now; history stays. An assignment already ended (or expired) is left as it is.
 * The current ACTOR must hold `roles.assign` in the assignment's scope (AccessDecider, E3.6).
 */
final class RevokeRoleAssignment
{
    public function __construct(private readonly AuditReason $reason, private readonly AccessDecider $access) {}

    public function handle(RoleAssignment $assignment, string $reason): RoleAssignment
    {
        return $this->reason->because($reason, fn () => DB::transaction(function () use ($assignment): RoleAssignment {
            $current = RoleAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize(Permission::RolesAssign, $current->scopeOrganization, 'role_assignment', $current->public_id);
            $now = CarbonImmutable::now('UTC');
            if ($current->valid_to !== null && $current->valid_to->lessThanOrEqualTo($now)) {
                return $current;
            }
            if ($current->valid_to === null) {
                return $current->end($now);
            }

            return $current->shortenScheduledEnd($now);
        }));
    }
}
