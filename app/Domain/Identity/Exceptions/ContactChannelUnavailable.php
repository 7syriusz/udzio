<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/** No delivery provider is configured for this channel, so its contacts cannot be verified (Z-020). */
final class ContactChannelUnavailable extends RuntimeException {}
