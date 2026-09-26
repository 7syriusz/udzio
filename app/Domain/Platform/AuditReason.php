<?php

namespace App\Domain\Platform;

use Closure;
use InvalidArgumentException;

final class AuditReason
{
    private ?string $reason = null;

    public function current(): ?string
    {
        return $this->reason;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function because(string $reason, Closure $operation): mixed
    {
        if (trim($reason) === '' || mb_strlen($reason) > 4000) {
            throw new InvalidArgumentException('Audit reason must contain 1 to 4000 characters.');
        }
        $previous = $this->reason;
        $this->reason = $reason;

        try {
            return $operation();
        } finally {
            $this->reason = $previous;
        }
    }
}
