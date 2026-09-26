<?php

namespace App\Domain\Platform\Exceptions;

use RuntimeException;

/** The idempotency key was already used for a request with different content. */
final class IdempotencyConflict extends RuntimeException {}
