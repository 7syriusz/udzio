<?php

namespace App\Domain\Organization\Access;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Security conditions an account must meet before a role assignment gives it anything (E3.8, A5 §12).
 * Every permission needs a verified e-mail. MFA is required by the role's security policy (`requires_mfa`)
 * or by holding a privileged permission (`organization.privileged_access.permissions`) — never by the
 * role's name. The assignment itself may exist; only its permissions stay inactive until the conditions hold.
 */
final class PrivilegedAccessPolicy
{
    public const REASON_EMAIL_UNVERIFIED = 'email_unverified';

    public const REASON_MFA_REQUIRED = 'mfa_required';

    /** @param array<string, mixed> $roleVersion content of the role version valid at the moment */
    public function requiresMfa(array $roleVersion): bool
    {
        return (bool) ($roleVersion['requires_mfa'] ?? false)
            || array_intersect($roleVersion['permissions'] ?? [], $this->privilegedPermissions()) !== [];
    }

    /**
     * Why the account cannot use the role version at the moment, or null when it can. MFA counts from its
     * confirmation, so a reconstructed decision before that moment is denied as it was then.
     *
     * @param  array<string, mixed>  $roleVersion
     */
    public function unmetCondition(User $account, array $roleVersion, CarbonImmutable $at): ?string
    {
        if ($account->email_verified_at === null) {
            return self::REASON_EMAIL_UNVERIFIED;
        }
        if ($this->requiresMfa($roleVersion) && ! $this->hasMfaAt($account, $at)) {
            return self::REASON_MFA_REQUIRED;
        }

        return null;
    }

    public function hasMfaAt(User $account, CarbonImmutable $at): bool
    {
        return $account->hasConfirmedTwoFactor() && ! $account->two_factor_confirmed_at->greaterThan($at);
    }

    /**
     * The security condition to name when no assignment matched, so the user is sent to verify the e-mail or to
     * set up MFA instead of getting a bare denial; null when no assignment failed only on those conditions.
     *
     * @param  list<string>  $outcomes  outcome of each considered assignment
     */
    public function unmetAmong(array $outcomes): ?string
    {
        foreach ([self::REASON_EMAIL_UNVERIFIED, self::REASON_MFA_REQUIRED] as $reason) {
            if (in_array($reason, $outcomes, true)) {
                return $reason;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function privilegedPermissions(): array
    {
        return config('organization.privileged_access.permissions', []);
    }
}
