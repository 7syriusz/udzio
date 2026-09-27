<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Identity\Models\Person;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Exceptions\ValidityConflict;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Call locking methods inside a transaction. All membership writes lock the PERSON first. */
final class MembershipRules
{
    public function functionName(string $function): string
    {
        return Validator::make(['function' => trim($function)], [
            'function' => ['required', 'string', 'max:100', 'regex:/\S/u'],
        ])->validate()['function'];
    }

    public function lockPerson(int $id): Person
    {
        return Person::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function activeOrganization(int $id): Organization
    {
        $organization = Organization::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($organization->status !== OrganizationStatus::Active) {
            throw ValidationException::withMessages(['organization' => 'Organizacja musi być aktywna.']);
        }

        return $organization;
    }

    public function current(Membership $membership): Membership
    {
        $stored = Membership::query()->findOrFail($membership->getKey());
        $this->lockPerson($stored->person_id);
        $current = Membership::query()->whereKey($stored->id)->lockForUpdate()->firstOrFail();
        if ($current->valid_to !== null) {
            throw new ValidityConflict('This membership period is already closed. Reload its current state.');
        }

        return $current;
    }
}
