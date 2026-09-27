<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Models\AccessRole;
use App\Domain\Platform\AuditReason;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Renames a role and/or replaces its permission set; the audit keeps before and after (A5-04). */
final class UpdateAccessRole
{
    public function __construct(private readonly AuditReason $reason) {}

    /** @param list<string> $permissions */
    public function handle(AccessRole $role, string $name, array $permissions, string $reason): AccessRole
    {
        $name = AccessRoleRules::name($name);
        $permissions = AccessRoleRules::permissions($permissions);

        try {
            return $this->reason->because($reason, fn () => DB::transaction(function () use ($role, $name, $permissions): AccessRole {
                $current = AccessRole::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
                $current->fill(['name' => $name, 'permissions' => $permissions]);
                $current->save();

                return $current;
            }));
        } catch (QueryException $e) {
            throw AccessRoleName::duplicate($e);
        }
    }
}
