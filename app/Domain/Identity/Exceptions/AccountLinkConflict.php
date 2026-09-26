<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/** The account is already linked to another PERSON, or the PERSON already has an account. */
final class AccountLinkConflict extends RuntimeException {}
