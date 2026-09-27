<?php

namespace App\Domain\Organization\Access;

use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\ActorType;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * Explicit, audited elevation for a technical process without an account — installation (first
 * administrator, E3.8) or maintenance commands. Recognized by AccessDecider only for a `process` ACTOR;
 * an HTTP request (account or anonymous) can never use it.
 */
final class SystemAuthority
{
    private ?string $reason = null;

    public function __construct(private readonly ActorContext $context) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(string $reason, Closure $operation): mixed
    {
        $previous = $this->reason;
        $this->enter($reason);

        try {
            return $operation();
        } finally {
            $this->reason = $previous;
        }
    }

    /** For a whole command lifetime (installation, E3.8) and test setup; business code uses `run`. */
    public function enter(string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('System authority requires a reason.');
        }
        if ($this->context->current()->type !== ActorType::Process) {
            throw new LogicException('System authority is available only to a technical process.');
        }
        $this->reason = $reason;
    }

    public function leave(): void
    {
        $this->reason = null;
    }

    public function activeReason(): ?string
    {
        return $this->context->current()->type === ActorType::Process ? $this->reason : null;
    }
}
