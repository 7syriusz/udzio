<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/** The representation (or its extension) is not allowed by the rules or the configuration (Z-025). */
final class RepresentationNotAllowed extends RuntimeException {}
