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
    ) {
        // A translation key: rendered in the user's language, never as a technical reason.
        parent::__construct('access.unauthorized');
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
