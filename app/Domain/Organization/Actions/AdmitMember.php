<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Identity\Models\Person;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AdmitMember
{
    public function __construct(private readonly AuditReason $reason, private readonly MembershipRules $rules) {}

    public function handle(Person $person, Organization $organization, string $function, string $reason): Membership
    {
        $function = $this->rules->functionName($function);

        return $this->reason->because($reason, fn () => DB::transaction(function () use ($person, $organization, $function): Membership {
            $this->rules->lockPerson($person->id);
            $this->rules->activeOrganization($organization->id);

            return Membership::startPeriod([
                'person_id' => $person->id,
                'organization_id' => $organization->id,
                'function' => $function,
            ], CarbonImmutable::now('UTC'));
        }, attempts: 3));
    }
}
