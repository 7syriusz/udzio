<?php

namespace App\Domain\Platform\Enums;

use App\Domain\Platform\Classification\ClassPolicy;

/** Data classification (A5 §12). The scenario classifies fields; policies per class live in config. */
enum DataClass: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Restricted = 'restricted';
    case Secret = 'secret';
    case SpecialCategory = 'special_category';

    public function policy(): ClassPolicy
    {
        return ClassPolicy::for($this);
    }
}
