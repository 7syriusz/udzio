<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\PlatformAccess;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Organization\Models\PlatformRoleAssignment;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives an account a platform role (`platform.administrators.manage`, E3.8b). Nobody grants a platform role
 * to their own account. The role works only once the account has a verified e-mail and confirmed MFA.
 */
final class GrantPlatformRole
{
    public function __construct(
        private readonly PlatformAccess $access,
        private readonly AuditReason $reason,
        private readonly OperationCorrelation $operation,
    ) {}

    public function handle(User $account, string $role, string $reason): PlatformRoleAssignment
    {
        if (! is_array(config('platform.roles.'.$role))) {
            throw ValidationException::withMessages(['role' => __('platform.validation.role_unknown')]);
        }

        return $this->operation->within(fn () => $this->reason->because($reason, fn () => DB::transaction(function () use ($account, $role): PlatformRoleAssignment {
            $current = User::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize(PlatformPermission::AdministratorsManage, $current);

            return PlatformRoleAssignment::startPeriod(['user_id' => $current->id, 'role' => $role], CarbonImmutable::now('UTC'));
        })));
    }
}
