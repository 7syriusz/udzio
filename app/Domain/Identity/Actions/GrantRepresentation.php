<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\Representation;
use App\Domain\Platform\AuditReason;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Establishes that `$representative` may act for `$represented` within `$scopes` from `$from` (Z-025).
 * Records method, basis and the establishing ACTOR. Which screen or role may call it (and which
 * confirmations precede it) belongs to the calling flow and its permission (E3+).
 */
final class GrantRepresentation
{
    public function __construct(private readonly AuditReason $reason, private readonly RepresentationRules $rules) {}

    /** @param list<RepresentationScope> $scopes */
    public function handle(Person $representative, Person $represented, array $scopes, RepresentationMethod $method, string $basis, DateTimeInterface $from, string $reason): Representation
    {
        if ($representative->is($represented)) {
            throw new InvalidArgumentException('A person does not represent themselves.');
        }
        $attributes = $this->rules->attributes($representative, $scopes, $method, $basis);

        return $this->reason->because($reason, fn () => Representation::startPeriod([
            'representative_person_id' => $representative->id,
            'represented_person_id' => $represented->id,
            ...$attributes,
        ], $from));
    }
}
