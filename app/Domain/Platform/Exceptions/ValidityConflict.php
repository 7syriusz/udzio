<?php

namespace App\Domain\Platform\Exceptions;

use RuntimeException;

/** A validity period would overlap another period of the same relation, or is not open (E1.5). */
class ValidityConflict extends RuntimeException {}
