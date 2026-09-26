<?php

namespace App\Domain\Platform\Enums;

/** Status of one validity period of a relation (A5 §2.3). Ending is a closed period, not a status. */
enum RelationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
