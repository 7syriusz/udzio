<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Representation;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\RelationStatus;
use DateTimeInterface;

/**
 * New scopes from `$at`: the current period closes, a new one opens with its own method, basis and
 * establishing ACTOR (history of what was allowed when, and why). Same rules as establishing (Z-025).
 */
final class ChangeRepresentationScopes
{
    public function __construct(private readonly AuditReason $reason, private readonly RepresentationRules $rules) {}

    /** @param list<RepresentationScope> $scopes */
    public function handle(Representation $representation, array $scopes, RepresentationMethod $method, string $basis, DateTimeInterface $at, string $reason): Representation
    {
        $attributes = $this->rules->attributes($representation->representative, $scopes, $method, $basis);

        return $this->reason->because($reason, fn () => $representation->transition(RelationStatus::Active, $at, $attributes));
    }
}
