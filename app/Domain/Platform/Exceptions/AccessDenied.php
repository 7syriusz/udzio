<?php

namespace App\Domain\Platform\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Denial of an operation outside the actor's PERMISSION or SCOPE/CONTEXT (A5-14).
 *
 * Carries the SUBJECT that was requested so the denial can be audited precisely. With
 * `hideAsNotFound()` the response is 404, so the caller cannot learn that the record exists
 * in another organization; the audit still records a denial.
 */
class AccessDenied extends AuthorizationException
{
    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly ?string $organizationId = null,
        public readonly ?string $ability = null,
        string $messageKey = 'access.unauthorized',
    ) {
        // A translation key: rendered in the user's language, never as a technical reason.
        parent::__construct($messageKey);
    }

    /**
     * Message for a denial reason. Only reasons the user can fix themselves get their own guidance (verify the
     * e-mail, set up MFA, E3.8); every other reason stays the general text and is visible in the audit only.
     */
    public static function messageFor(string $reason): string
    {
        return match ($reason) {
            'mfa_required' => 'access.mfa_required',
            'email_unverified' => 'access.email_unverified',
            'candidate_outside_scope', 'candidates_outside_scope' => 'access.escalation_required',
            default => 'access.unauthorized',
        };
    }

    /** True when the denial was already written to the audit (e.g. with its decision basis). */
    public bool $recorded = false;

    public function alreadyRecorded(): static
    {
        $this->recorded = true;

        return $this;
    }

    public function hideAsNotFound(): static
    {
        return $this->withStatus(404);
    }
}
