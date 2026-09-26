<?php

namespace App\Actions\Fortify\TwoFactor;

use App\Domain\Platform\AuditReason;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/** Fortify's action with an explicit audit reason for its write to the account (A5-04). */
class AuditedDisableTwoFactorAuthentication extends DisableTwoFactorAuthentication
{
    public function __invoke($user)
    {
        return app(AuditReason::class)->because('two-factor authentication disabled by account holder', fn () => parent::__invoke($user));
    }
}
