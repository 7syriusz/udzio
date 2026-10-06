<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Identity\Actions\ClearAccountMfa;
use App\Domain\Organization\Access\PlatformAccess;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Emergency MFA reset (E3.8d, Z-040) for when no platform administrator can use `platform.mfa.reset`. Only a
 * console process under SystemAuthority with the `platform.emergency.mfa_reset` purpose may run it — never an
 * account or an HTTP request. It has the same effect as the operator reset (no MFA, no recovery codes, no
 * sessions, e-mail to the holder) and grants nothing: no role, no new administrator, no way past MFA — the
 * holder must set MFA up again before platform permissions work. Audited as `account.mfa_reset` with
 * `procedure: emergency` and the server operator.
 */
final class EmergencyResetAccountMfa
{
    public function __construct(
        private readonly PlatformAccess $access,
        private readonly ClearAccountMfa $clear,
        private readonly RecordAudit $audit,
        private readonly AuditReason $reason,
        private readonly OperationCorrelation $operation,
    ) {}

    /**
     * @param  string  $identityConfirmation  how the holder's identity was confirmed
     * @param  string  $operator  who ran it on the server (system user and host), recorded in the audit
     */
    public function handle(User $account, string $identityConfirmation, string $reason, string $operator): void
    {
        $input = Validator::make(['identity_confirmation' => trim($identityConfirmation), 'reason' => trim($reason)], [
            'identity_confirmation' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        $this->operation->within(fn () => $this->reason->because($input['reason'], fn () => DB::transaction(function () use ($account, $input, $operator): void {
            $current = User::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize(PlatformPermission::EmergencyMfaReset, $current);
            $ended = $this->clear->handle($current, $input['reason']);
            $this->audit->handle('account.mfa_reset', 'account', (string) $current->id, reason: $input['reason'], after: [
                'procedure' => 'emergency',
                'operator' => $operator,
                'identity_confirmation' => $input['identity_confirmation'],
                'sessions_ended' => $ended,
                'recovery_codes_revoked' => true,
            ]);
        })));
    }
}
