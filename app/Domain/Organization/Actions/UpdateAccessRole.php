<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Renames a role and/or replaces its permissions and role-granting catalog (null keeps the catalog);
 * the audit keeps before and after (A5-04) and a new version is published. Nobody changes a role they hold.
 */
final class UpdateAccessRole
{
    public function __construct(
        private readonly AuditReason $reason,
        private readonly AccessDecider $access,
        private readonly OperationCorrelation $operation,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @param  list<array{role: string, include_descendants?: bool, max_days?: int|null, requires_approval?: bool}>|null  $grantRules
     */
    public function handle(AccessRole $role, string $name, array $permissions, string $reason, ?array $grantRules = null): AccessRole
    {
        $name = AccessRoleRules::name($name);
        $permissions = AccessRoleRules::permissions($permissions);

        try {
            return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($role, $name, $permissions, $grantRules): AccessRole {
                $current = AccessRole::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
                $this->access->authorizeRoleDefinition($current->organization, $current);
                $current->fill([
                    'name' => $name,
                    'permissions' => $permissions,
                    'grant_rules' => AccessRoleRules::grantRules($grantRules ?? $current->grant_rules ?? [], $current->organization, $permissions),
                ]);
                $current->save();
                $current->publishVersion();

                return $current;
            })));
        } catch (QueryException $e) {
            throw AccessRoleName::duplicate($e);
        }
    }
}
