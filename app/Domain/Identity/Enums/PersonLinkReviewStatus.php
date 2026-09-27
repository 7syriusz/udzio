<?php

namespace App\Domain\Identity\Enums;

enum PersonLinkReviewStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
