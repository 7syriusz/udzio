<?php

namespace App\Domain\Platform\Enums;

enum ActorType: string
{
    case Account = 'account';
    case Process = 'process';
    case Integration = 'integration';
    case Anonymous = 'anonymous';
}
