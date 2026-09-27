<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Defines a role (`roles.manage`, authorized centrally by AccessDecider) and publishes its first version. */
final class CreateAccessRole
{
    public function __construct(
        private readonly AuditReason $reason,
        private readonly AccessDecider $access,
        private readonly OperationCorrelation $operation,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @param  list<array{role: string, include_descendants?: bool, max_days?: int|null, requires_approval?: bool}>  $grantRules
     */
    public function handle(Organization $organization, string $name, array $permissions, string $reason, array $grantRules = []): AccessRole
    {
        $name = AccessRoleRules::name($name);
        $permissions = AccessRoleRules::permissions($permissions);

        try {
            return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($organization, $name, $permissions, $grantRules): AccessRole {
                $current = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
                $this->access->authorizeRoleDefinition($current);
                if ($current->status !== OrganizationStatus::Active) {
                    throw ValidationException::withMessages(['organization' => 'Organizacja musi być aktywna.']);
                }
                $role = AccessRole::query()->create([
                    'organization_id' => $current->id,
                    'name' => $name,
                    'permissions' => $permissions,
                    'grant_rules' => AccessRoleRules::grantRules($grantRules, $current, $permissions),
                    'status' => AccessRoleStatus::Active,
                ]);
                $role->publishVersion();

                return $role;
            })));
        } catch (QueryException $e) {
            throw AccessRoleName::duplicate($e);
        }
    }
}
