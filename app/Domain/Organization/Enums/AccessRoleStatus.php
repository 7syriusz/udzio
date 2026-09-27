<?php

namespace App\Domain\Organization\Enums;

enum AccessRoleStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
