<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateAccessRole
{
    public function __construct(private readonly AuditReason $reason) {}

    /** @param list<string> $permissions */
    public function handle(Organization $organization, string $name, array $permissions, string $reason): AccessRole
    {
        $name = AccessRoleRules::name($name);
        $permissions = AccessRoleRules::permissions($permissions);

        try {
            return $this->reason->because($reason, fn () => DB::transaction(function () use ($organization, $name, $permissions): AccessRole {
                $current = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
                if ($current->status !== OrganizationStatus::Active) {
                    throw ValidationException::withMessages(['organization' => 'Organizacja musi być aktywna.']);
                }

                return AccessRole::query()->create([
                    'organization_id' => $current->id,
                    'name' => $name,
                    'permissions' => $permissions,
                    'status' => AccessRoleStatus::Active,
                ]);
            }));
        } catch (QueryException $e) {
            throw AccessRoleName::duplicate($e);
        }
    }
}
