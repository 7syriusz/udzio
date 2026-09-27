<?php

namespace App\Domain\Organization\Enums;

/** Explicit inheritance policy of a role assignment (A5 §2.4: no automatic inheritance in every direction). */
enum ScopeInheritance: string
{
    /** Only the scope unit itself. */
    case UnitOnly = 'unit_only';
    /** The scope unit and every unit below it in the structure at the moment of the check. */
    case UnitAndDescendants = 'unit_and_descendants';
}
