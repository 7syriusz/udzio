<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Models\Membership;
use App\Domain\Platform\AuditReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EndMembership
{
    public function __construct(private readonly AuditReason $reason, private readonly MembershipRules $rules) {}

    public function handle(Membership $membership, string $reason): Membership
    {
        return $this->reason->because($reason, fn () => DB::transaction(
            fn () => $this->rules->current($membership)->end(CarbonImmutable::now('UTC')),
            attempts: 3,
        ));
    }
}
