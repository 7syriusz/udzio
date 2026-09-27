<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class TransferMembership
{
    public function __construct(private readonly AuditReason $reason, private readonly MembershipRules $rules) {}

    public function handle(Membership $membership, Organization $destination, string $reason): Membership
    {
        return $this->reason->because($reason, fn () => DB::transaction(function () use ($membership, $destination): Membership {
            $current = $this->rules->current($membership);
            $this->rules->activeOrganization($destination->id);
            if ($current->organization_id === $destination->id) {
                return $current;
            }
            $at = CarbonImmutable::now('UTC');
            $current->end($at);

            return Membership::startPeriod([
                'person_id' => $current->person_id,
                'organization_id' => $destination->id,
                'function' => $current->function,
                'transferred_from_id' => $current->id,
            ], $at, $current->status);
        }, attempts: 3));
    }
}
