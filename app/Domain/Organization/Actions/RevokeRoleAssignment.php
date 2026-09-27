<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\AuditReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Ends an assignment now; history stays. An assignment already ended (or expired) is left as it is. */
final class RevokeRoleAssignment
{
    public function __construct(private readonly AuditReason $reason) {}

    public function handle(RoleAssignment $assignment, string $reason): RoleAssignment
    {
        return $this->reason->because($reason, fn () => DB::transaction(function () use ($assignment): RoleAssignment {
            $current = RoleAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
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
