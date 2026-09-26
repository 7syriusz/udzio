<?php

namespace App\Domain\Platform;

use App\Domain\Platform\Enums\ActorType;
use InvalidArgumentException;

final readonly class Actor
{
    public function __construct(public ActorType $type, public ?string $identifier = null)
    {
        if ($type === ActorType::Anonymous && $identifier !== null) {
            throw new InvalidArgumentException('An anonymous actor cannot have an identity.');
        }

        if ($type !== ActorType::Anonymous && ($identifier === null || trim($identifier) === '')) {
            throw new InvalidArgumentException('An identified actor requires a non-empty identifier.');
        }
    }

    public static function account(string $identifier): self
    {
        return new self(ActorType::Account, $identifier);
    }

    public static function process(string $identifier): self
    {
        return new self(ActorType::Process, $identifier);
    }

    public static function integration(string $identifier): self
    {
        return new self(ActorType::Integration, $identifier);
    }

    public static function anonymous(): self
    {
        return new self(ActorType::Anonymous);
    }

    /** @return array{type: string, identifier: ?string} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'identifier' => $this->identifier];
    }
}
