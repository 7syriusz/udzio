<?php

namespace App\Actions\Fortify\TwoFactor;

use App\Domain\Platform\AuditReason;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;

/** Fortify's action with an explicit audit reason for its write to the account (A5-04). */
class AuditedConfirmTwoFactorAuthentication extends ConfirmTwoFactorAuthentication
{
    public function __invoke($user, $code)
    {
        return app(AuditReason::class)->because('two-factor authentication confirmed with code', fn () => parent::__invoke($user, $code));
    }
}
