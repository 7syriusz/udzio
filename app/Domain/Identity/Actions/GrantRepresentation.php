<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RepresentationKind;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\Representation;
use App\Domain\Platform\AuditReason;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Records that `$representative` may act for `$represented` within `$scopes` from `$from`. Who may call it
 * (operator, verified guardian flow) is decided by the calling screen/permission (E3, Z-025).
 */
final class GrantRepresentation
{
    public function __construct(private readonly AuditReason $reason) {}

    /** @param list<RepresentationScope> $scopes */
    public function handle(Person $representative, Person $represented, RepresentationKind $kind, array $scopes, DateTimeInterface $from, string $reason): Representation
    {
        if ($representative->is($represented)) {
            throw new InvalidArgumentException('A person does not represent themselves.');
        }
        if ($scopes === []) {
            throw new InvalidArgumentException('A representation needs at least one scope.');
        }

        return $this->reason->because($reason, fn () => Representation::startPeriod([
            'representative_person_id' => $representative->id,
            'represented_person_id' => $represented->id,
            'kind' => $kind,
            'scopes' => RepresentationScope::normalize($scopes),
        ], $from));
    }
}
