<?php

namespace App\Domain\Organization\Enums;

enum OrganizationStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
