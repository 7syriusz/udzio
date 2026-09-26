<?php

namespace App\Domain\Identity\Auth;

use App\Domain\Platform\AuditReason;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

/**
 * Eloquent provider whose own writes to the account (remember-token rotation, password rehash) carry
 * an explicit technical reason, as every audited change must (A5-04).
 */
class AuditedUserProvider extends EloquentUserProvider
{
    public function updateRememberToken(UserContract $user, #[\SensitiveParameter] $token): void
    {
        app(AuditReason::class)->because('session token rotated', fn () => parent::updateRememberToken($user, $token));
    }

    public function rehashPasswordIfRequired(UserContract $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        app(AuditReason::class)->because('password hash upgraded on login', fn () => parent::rehashPasswordIfRequired($user, $credentials, $force));
    }
}
