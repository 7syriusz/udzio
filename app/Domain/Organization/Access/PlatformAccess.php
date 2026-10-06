<?php

namespace App\Domain\Organization\Access;

use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Organization\Models\PlatformInstallation;
use App\Domain\Organization\Models\PlatformRoleAssignment;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Decisions on platform permissions (E3.8b) — separate from AccessDecider: only platform role assignments
 * count, never organization roles. Every platform permission is privileged, so it needs a verified e-mail and
 * confirmed MFA. A technical process under SystemAuthority gets only the console procedures declared by its
 * purpose: creating the first administrator while the platform is not installed yet, and the emergency MFA reset.
 */
final class PlatformAccess
{
    public const SUBJECT = 'platform';

    public function __construct(
        private readonly ActorContext $context,
        private readonly SystemAuthority $system,
        private readonly PrivilegedAccessPolicy $privileged,
        private readonly AccessDecider $decider,
        private readonly RecordAudit $audit,
        private readonly OperationCorrelation $correlation,
    ) {}

    public function decide(?User $account, PlatformPermission $permission, ?DateTimeInterface $at = null): AccessDecision
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now('UTC'))->utc();
        $basis = ['permission' => $permission->value, 'at' => $at->format('Y-m-d H:i:s.u'), 'account_id' => $account?->getKey(), 'scope' => self::SUBJECT];
        if ($account === null) {
            return $this->deny(AccessDecision::REASON_NO_ACCOUNT, $basis);
        }
        if ($permission === PlatformPermission::InstallFirstAdministrator) {
            return $this->deny('installation_only', $basis);
        }
        if ($permission === PlatformPermission::EmergencyMfaReset) {
            return $this->deny('console_only', $basis);
        }
        $assignments = PlatformRoleAssignment::query()->where('user_id', $account->getKey())->activeAt($at)->orderBy('id')->get();
        if ($assignments->isEmpty()) {
            return $this->deny(AccessDecision::REASON_NO_ACTIVE_ASSIGNMENT, $basis);
        }

        $considered = [];
        foreach ($assignments as $assignment) {
            $definition = config('platform.roles.'.$assignment->role);
            $outcome = match (true) {
                ! is_array($definition) => 'role_unknown',
                ! in_array($permission->value, $definition['permissions'], true) => 'permission_missing',
                // Platform permissions are privileged whatever the role definition says.
                default => $this->privileged->unmetCondition($account, [...$definition, 'requires_mfa' => true], $at) ?? 'match',
            };
            if ($outcome === 'match') {
                return new AccessDecision(true, AccessDecision::REASON_ASSIGNMENT, [...$basis, 'assignment' => $assignment->public_id, 'role' => $assignment->role, 'decision' => 'allowed', 'reason' => AccessDecision::REASON_ASSIGNMENT]);
            }
            $considered[] = ['assignment' => $assignment->public_id, 'outcome' => $outcome];
        }
        $reason = $this->privileged->unmetAmong(array_column($considered, 'outcome')) ?? AccessDecision::REASON_NO_MATCHING_ASSIGNMENT;

        return $this->deny($reason, [...$basis, 'considered' => $considered]);
    }

    /**
     * Decision for the current ACTOR, recorded like organization decisions: `access.granted` inside the caller's
     * transaction, `access.denied` on the independent audit connection followed by AccessDenied. With
     * `$subjectAccount` the operation concerns that account, and the account itself is refused — nobody uses
     * a platform role on their own account (e.g. resetting their own MFA).
     */
    public function authorize(PlatformPermission $permission, ?User $subjectAccount = null): AccessDecision
    {
        $subjectType = $subjectAccount === null ? self::SUBJECT : 'account';
        $subjectId = $subjectAccount === null ? $permission->value : (string) $subjectAccount->getKey();
        $actor = $this->context->current();
        $decision = $actor->type === ActorType::Account
            ? $this->decide(User::query()->find($actor->identifier), $permission)
            : $this->decideForProcess($permission);
        if ($decision->allowed && $subjectAccount !== null && $actor->type === ActorType::Account && $actor->identifier === (string) $subjectAccount->getKey()) {
            $decision = $this->deny('own_account', [...$decision->basis, 'subject_account_id' => $subjectAccount->getKey()]);
        }

        return $this->record($decision, $permission, $subjectType, $subjectId);
    }

    /** Records a refusal decided outside authorize (e.g. a lost race for the installation). */
    public function refuse(PlatformPermission $permission, string $reason): never
    {
        $this->record($this->deny($reason, ['permission' => $permission->value, 'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'), 'scope' => self::SUBJECT]), $permission, self::SUBJECT, $permission->value);
    }

    private function decideForProcess(PlatformPermission $permission): AccessDecision
    {
        $purpose = $this->system->activePurpose();
        $basis = ['permission' => $permission->value, 'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'), 'scope' => self::SUBJECT, 'system_purpose' => $purpose?->describe()];

        return match (true) {
            $purpose === null => $this->deny(AccessDecision::REASON_NO_ACCOUNT, $basis),
            ! in_array($permission, [PlatformPermission::InstallFirstAdministrator, PlatformPermission::EmergencyMfaReset], true) => $this->deny('system_authority_not_for_platform', $basis),
            ! $purpose->allows($permission) => $this->deny('system_purpose_permission_missing', $basis),
            $permission === PlatformPermission::InstallFirstAdministrator && PlatformInstallation::completed() => $this->deny('installation_completed', $basis),
            default => new AccessDecision(true, AccessDecision::REASON_SYSTEM_AUTHORITY, [...$basis, 'decision' => 'allowed', 'reason' => AccessDecision::REASON_SYSTEM_AUTHORITY]),
        };
    }

    private function record(AccessDecision $decision, PlatformPermission $permission, string $subjectType, string $subjectId): AccessDecision
    {
        if ($decision->allowed) {
            if ($this->correlation->firstTime(implode('|', ['granted', $permission->value, $subjectType, $subjectId]))) {
                $this->audit->handle('access.granted', $subjectType, $subjectId, after: $decision->basis);
            }

            return $decision;
        }
        $this->decider->recordDenial($subjectType, $subjectId, null, $decision->basis);

        throw (new AccessDenied($subjectType, $subjectId, null, $permission->value, AccessDenied::messageFor($decision->reason)))->alreadyRecorded();
    }

    /** @param array<string, mixed> $basis */
    private function deny(string $reason, array $basis): AccessDecision
    {
        return new AccessDecision(false, $reason, [...$basis, 'decision' => 'denied', 'reason' => $reason]);
    }
}
