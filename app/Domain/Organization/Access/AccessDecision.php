<?php

namespace App\Domain\Organization\Access;

/**
 * Result of AccessDecider: allowed or denied, never "unknown", with the basis needed to reconstruct it —
 * which assignment, role version, permission, scope and target unit, and which structure periods linked
 * them at that moment (or, for a denial, why each considered assignment did not apply).
 */
final readonly class AccessDecision
{
    public const REASON_ASSIGNMENT = 'assignment';

    public const REASON_SYSTEM_AUTHORITY = 'system_authority';

    public const REASON_NO_ACCOUNT = 'actor_without_account';

    public const REASON_TARGET_INACTIVE = 'target_inactive';

    public const REASON_NO_ACTIVE_ASSIGNMENT = 'no_active_assignment';

    public const REASON_NO_MATCHING_ASSIGNMENT = 'no_matching_assignment';

    /** @param array<string, mixed> $basis */
    public function __construct(public bool $allowed, public string $reason, public array $basis) {}

    public function denied(): bool
    {
        return ! $this->allowed;
    }
}
