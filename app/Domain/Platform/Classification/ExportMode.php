<?php

namespace App\Domain\Platform\Classification;

enum ExportMode: string
{
    /** Value is exported. */
    case Value = 'value';
    /** Field is present, value replaced by the redaction marker. */
    case Redacted = 'redacted';
    /** Field is left out entirely. */
    case Omitted = 'omitted';
}
