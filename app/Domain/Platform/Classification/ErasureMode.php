<?php

namespace App\Domain\Platform\Classification;

/** What happens to a field's value when its retention period ends or erasure is requested (Z-017). */
enum ErasureMode: string
{
    /** Value stays (not personal, or needed for settlement and reporting history). */
    case Keep = 'keep';
    /** Value is replaced so that the person cannot be identified; facts and totals stay. */
    case Anonymize = 'anonymize';
    /** Value is removed. */
    case Delete = 'delete';
}
