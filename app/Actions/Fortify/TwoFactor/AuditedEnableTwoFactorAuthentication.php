<?php

namespace App\Actions\Fortify\TwoFactor;

use App\Domain\Platform\AuditReason;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/** Fortify's action with an explicit audit reason for its write to the account (A5-04). */
class AuditedEnableTwoFactorAuthentication extends EnableTwoFactorAuthentication
{
    public function __invoke($user, $force = false)
    {
        return app(AuditReason::class)->because('two-factor authentication enabled by account holder', fn () => parent::__invoke($user, $force));
    }
}
