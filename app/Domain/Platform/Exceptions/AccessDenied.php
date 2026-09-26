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
        parent::__construct('This action is unauthorized.');
    }

    public function hideAsNotFound(): static
    {
        return $this->withStatus(404);
    }
}
