<?php

namespace App\Actions\Fortify;

use App\Domain\Platform\AuditReason;
use Illuminate\Contracts\Auth\StatefulGuard;
use Laravel\Fortify\Actions\CompletePasswordReset;

/** Fortify's final reset step rotates the remember token; the write gets an explicit reason (A5-04). */
class CompleteAuditedPasswordReset extends CompletePasswordReset
{
    public function __construct(private readonly AuditReason $reason) {}

    public function __invoke(StatefulGuard $guard, $user): void
    {
        $this->reason->because('remember token rotated after password reset', fn () => parent::__invoke($guard, $user));
    }
}
