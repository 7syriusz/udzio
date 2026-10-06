<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Notifications\MfaResetNotice;
use App\Domain\Platform\AuditReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes an account's second factor on someone else's decision (E3.8b, E3.8d): the TOTP secret and every
 * recovery code, then all sessions and "remember me" cookies. The holder is told by e-mail once the change is
 * committed. Grants nothing — privileged permissions stay off until MFA is set up again. Callers authorize
 * and audit the operation; this is only its effect.
 *
 * @return int number of ended sessions
 */
final class ClearAccountMfa
{
    public function __construct(
        private readonly AuditReason $reason,
        private readonly InvalidateAccountSessions $sessions,
    ) {}

    public function handle(User $account, string $reason): int
    {
        $this->reason->because($reason, fn () => $account->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save());
        $ended = $this->sessions->handle($account, 'sessions ended by MFA reset');
        DB::afterCommit(fn () => $account->notify(new MfaResetNotice));

        return $ended;
    }
}
