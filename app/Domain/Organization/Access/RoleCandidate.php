<?php

namespace App\Domain\Organization\Access;

/**
 * Minimal data for choosing an account to receive a role (E3.7b): enough to point at one account, nothing
 * more. The e-mail is masked unless the manager holds people.contacts.view in the scope.
 */
final readonly class RoleCandidate
{
    public function __construct(
        public string $personId,
        public string $fullName,
        public string $email,
        public bool $emailMasked,
        public bool $accountActive,
        public bool $inRequestedScope,
    ) {}
}
