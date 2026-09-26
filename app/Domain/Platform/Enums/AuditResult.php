<?php

namespace App\Domain\Platform\Enums;

enum AuditResult: string
{
    case Succeeded = 'succeeded';
    case Denied = 'denied';
    case Failed = 'failed';
}
