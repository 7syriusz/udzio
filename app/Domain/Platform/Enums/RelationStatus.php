<?php

namespace App\Domain\Platform\Enums;

/** Status of one validity period of a relation (A5 §2.3). Ending is a closed period, not a status. */
enum RelationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    /** Requested, waiting for approval; grants nothing (e.g. a role assignment that needs approval). */
    case Pending = 'pending';
}
