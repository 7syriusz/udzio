<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Representation;
use App\Domain\Platform\AuditReason;
use DateTimeInterface;

/** From `$at` the representative no longer acts for the represented person. */
final class EndRepresentation
{
    public function __construct(private readonly AuditReason $reason) {}

    public function handle(Representation $representation, DateTimeInterface $at, string $reason): Representation
    {
        return $this->reason->because($reason, fn () => $representation->end($at));
    }
}
