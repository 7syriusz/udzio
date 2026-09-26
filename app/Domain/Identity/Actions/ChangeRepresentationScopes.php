<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Representation;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\RelationStatus;
use DateTimeInterface;
use InvalidArgumentException;

/** New scopes from `$at`: the current period closes, a new one opens (history of what was allowed when). */
final class ChangeRepresentationScopes
{
    public function __construct(private readonly AuditReason $reason) {}

    /** @param list<RepresentationScope> $scopes */
    public function handle(Representation $representation, array $scopes, DateTimeInterface $at, string $reason): Representation
    {
        if ($scopes === []) {
            throw new InvalidArgumentException('Use EndRepresentation to remove all scopes.');
        }

        return $this->reason->because($reason, fn () => $representation->transition(RelationStatus::Active, $at, ['scopes' => RepresentationScope::normalize($scopes)]));
    }
}
