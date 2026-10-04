<?php

namespace App\Domain\Organization\Access;

use Carbon\CarbonImmutable;

/**
 * What an approver sees about a pending role request (E3.7b): who it concerns (name, masked e-mail), which
 * role, scope and period, who asked, when and why. Seeing it gives no access to other data of that person.
 */
final readonly class RoleRequestSummary
{
    public function __construct(
        public string $requestId,
        public string $granteeName,
        public string $granteeEmail,
        public string $roleName,
        public string $scopeName,
        public string $scopeInheritance,
        public ?CarbonImmutable $requestedUntil,
        public ?string $requestedBy,
        public ?CarbonImmutable $requestedAt,
        public ?string $reason,
    ) {}
}
