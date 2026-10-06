<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Identity\Actions\InvalidateAccountSessions;
use App\Domain\Organization\Access\PlatformAccess;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Resets another account's MFA (E3.8b) when its holder lost the second factor. Requires `platform.mfa.reset`
 * (with the operator's own MFA) and a recorded confirmation of the holder's identity; the holder can never do
 * it with their own platform role. Removes the TOTP secret and every recovery code, ends all sessions and
 * "remember me" cookies; privileged permissions stop until MFA is set up again. Audited as `account.mfa_reset`.
 */
final class ResetAccountMfa
{
    public function __construct(
        private readonly PlatformAccess $access,
        private readonly InvalidateAccountSessions $sessions,
        private readonly RecordAudit $audit,
        private readonly AuditReason $reason,
        private readonly OperationCorrelation $operation,
    ) {}

    /** @param string $identityConfirmation how the holder's identity was confirmed (e.g. document checked during a video call) */
    public function handle(User $account, string $identityConfirmation, string $reason): void
    {
        $input = Validator::make(['identity_confirmation' => trim($identityConfirmation), 'reason' => trim($reason)], [
            'identity_confirmation' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        $this->operation->within(fn () => $this->reason->because($input['reason'], fn () => DB::transaction(function () use ($account, $input): void {
            $current = User::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize(PlatformPermission::MfaReset, $current);
            $current->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
            $ended = $this->sessions->handle($current, 'sessions ended by MFA reset');
            $this->audit->handle('account.mfa_reset', 'account', (string) $current->id, reason: $input['reason'], after: [
                'identity_confirmation' => $input['identity_confirmation'],
                'sessions_ended' => $ended,
                'recovery_codes_revoked' => true,
            ]);
        })));
    }
}
