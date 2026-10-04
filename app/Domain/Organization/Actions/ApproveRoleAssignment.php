<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\RelationStatus;
use App\Domain\Platform\Exceptions\ValidityConflict;
use App\Domain\Platform\OperationCorrelation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves a pending assignment (E3.6a): another account allowed to grant this role in this scope — never
 * the requester or the grantee. The pending period closes and the active one starts now (history kept).
 * Rejection is RevokeRoleAssignment of the pending assignment.
 */
final class ApproveRoleAssignment
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
            if ($current->status !== RelationStatus::Pending || $current->valid_to !== null) {
                throw new ValidityConflict('Only a pending assignment can be approved.');
            }
            $this->access->authorizeRoleGrant('approve', $current->role, $current->scopeOrganization, $current->requested_until, $current->user_id,
                $current->requested_by_type === 'account' ? $current->requested_by_id : null, $current->public_id);
            $now = CarbonImmutable::now('UTC');
            if ($current->requested_until !== null && $current->requested_until->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['until' => __('organization.validation.request_expired')]);
            }
            $active = $current->transition(RelationStatus::Active, $now);

            return $current->requested_until === null ? $active : $active->end($current->requested_until);
        }, attempts: 3)));
    }
}
