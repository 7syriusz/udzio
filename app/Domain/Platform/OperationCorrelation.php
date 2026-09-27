<?php

namespace App\Domain\Platform;

use Closure;
use Illuminate\Support\Str;

/**
 * One protected operation = one correlation ID shared by all its audit entries (decision, changes).
 * Nested operations join the outer one. Also lets the access decision be recorded once per operation,
 * however many technical checks it performs.
 */
final class OperationCorrelation
{
    private ?string $id = null;

    /** @var array<string, true> */
    private array $recorded = [];

    public function current(): ?string
    {
        return $this->id;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function within(Closure $operation): mixed
    {
        if ($this->id !== null) {
            return $operation();
        }
        $this->id = (string) Str::ulid();

        try {
            return $operation();
        } finally {
            $this->id = null;
            $this->recorded = [];
        }
    }

    /** True the first time `$key` is seen in the current operation (always true outside an operation). */
    public function firstTime(string $key): bool
    {
        if ($this->id === null) {
            return true;
        }
        if (isset($this->recorded[$key])) {
            return false;
        }
        $this->recorded[$key] = true;

        return true;
    }
}
