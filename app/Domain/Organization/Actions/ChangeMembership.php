<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Models\Membership;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\RelationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ChangeMembership
{
    public function __construct(private readonly AuditReason $reason, private readonly MembershipRules $rules) {}

    public function handle(Membership $membership, string $function, RelationStatus $status, string $reason): Membership
    {
        $function = $this->rules->functionName($function);

        return $this->reason->because($reason, fn () => DB::transaction(function () use ($membership, $function, $status): Membership {
            $current = $this->rules->current($membership);
            $this->rules->activeOrganization($current->organization_id);
            if ($current->function === $function && $current->status === $status) {
                return $current;
            }

            return $current->transition($status, CarbonImmutable::now('UTC'), ['function' => $function]);
        }, attempts: 3));
    }
}
