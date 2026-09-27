<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateAccessRole
{
    public function __construct(private readonly AuditReason $reason, private readonly AccessDecider $access) {}

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

                $this->access->authorizeDelegation(Permission::RolesManage, $current, $permissions, null, 'access_role');
                $role = AccessRole::query()->create([
                    'organization_id' => $current->id,
                    'name' => $name,
                    'permissions' => $permissions,
                    'status' => AccessRoleStatus::Active,
                ]);
                $role->publishVersion();

                return $role;
            }));
        } catch (QueryException $e) {
            throw AccessRoleName::duplicate($e);
        }
    }
}
