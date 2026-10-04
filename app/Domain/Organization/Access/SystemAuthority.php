<?php

namespace App\Domain\Organization\Access;

use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\ActorType;
use Closure;
use LogicException;

/**
 * Explicit, audited elevation for a technical process without an account — installation (first administrator,
 * E3.8), maintenance, background parts of exports and reports. Never general access to all data (E3.7b):
 * it is bound to a declared SystemPurpose (purpose, basis, permissions, scope, ordering account). Recognized
 * by AccessDecider only for a `process` ACTOR; an HTTP request (account or anonymous) can never use it.
 * Entering it is audited.
 */
final class SystemAuthority
{
    private ?SystemPurpose $purpose = null;

    public function __construct(
        private readonly ActorContext $context,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(SystemPurpose $purpose, Closure $operation): mixed
    {
        $previous = $this->purpose;
        $this->enter($purpose);

        try {
            return $operation();
        } finally {
            $this->purpose = $previous;
        }
    }

    /** For a whole command lifetime (installation, E3.8) and test setup; business code uses `run`. */
    public function enter(SystemPurpose $purpose): void
    {
        if ($this->context->current()->type !== ActorType::Process) {
            throw new LogicException('System authority is available only to a technical process.');
        }
        $this->audit->handle('system_authority.entered', 'system_purpose', $purpose->purpose, reason: $purpose->basis, after: $purpose->describe(), connection: 'audit');
        $this->purpose = $purpose;
    }

    public function leave(): void
    {
        $this->purpose = null;
    }

    public function activePurpose(): ?SystemPurpose
    {
        return $this->context->current()->type === ActorType::Process ? $this->purpose : null;
    }
}
