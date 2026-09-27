<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Platform\AuditReason;
use Illuminate\Support\Facades\DB;

/** A retired role grants nothing and cannot change; its history and name stay for the audit. */
final class RetireAccessRole
{
    public function __construct(private readonly AuditReason $reason, private readonly AccessDecider $access) {}

    public function handle(AccessRole $role, string $reason): AccessRole
    {
        return $this->reason->because($reason, fn () => DB::transaction(function () use ($role): AccessRole {
            $current = AccessRole::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize(Permission::RolesManage, $current->organization, 'access_role', $current->public_id);
            if ($current->status === AccessRoleStatus::Retired) {
                return $current;
            }
            $current->status = AccessRoleStatus::Retired;
            $current->save();
            $current->publishVersion();

            return $current;
        }));
    }
}
