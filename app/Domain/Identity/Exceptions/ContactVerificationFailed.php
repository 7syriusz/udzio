<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/** Wrong, expired, exhausted or missing code. The message never tells which, to avoid probing. */
final class ContactVerificationFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The verification code is invalid or expired.');
    }
}
