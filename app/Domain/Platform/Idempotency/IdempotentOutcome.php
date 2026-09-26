<?php

namespace App\Domain\Platform\Idempotency;

final readonly class IdempotentOutcome
{
    public function __construct(
        /** Result of the operation (JSON-compatible), identical for the first run and every replay. */
        public mixed $value,
        /** True when the stored result of an earlier run was returned and the operation did not run. */
        public bool $replayed,
    ) {}
}
