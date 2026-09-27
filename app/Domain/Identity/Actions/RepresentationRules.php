<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Exceptions\RepresentationNotAllowed;
use App\Domain\Identity\Models\Person;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\ActorType;
use App\Models\User;
use InvalidArgumentException;

/**
 * Checks shared by establishing and changing a representation (Z-025). The configuration chooses the
 * enabled methods and the scopes that may be granted; the rule that nobody establishes or extends a
 * representation for themselves is not configurable.
 */
final class RepresentationRules
{
    public function __construct(private readonly ActorContext $context) {}

    /**
     * @param  list<RepresentationScope>  $scopes
     * @return array{scopes: list<string>, method: string, basis: string, established_by_type: string, established_by_id: ?string}
     */
    public function attributes(Person $representative, array $scopes, RepresentationMethod $method, string $basis): array
    {
        $basis = trim($basis);
        if ($scopes === []) {
            throw new InvalidArgumentException('A representation needs at least one scope.');
        }
        if ($basis === '' || mb_strlen($basis) > 500) {
            throw new InvalidArgumentException('A representation needs a basis of 1 to 500 characters.');
        }
        if (! in_array($method->value, config('identity.representation.methods', []), true)) {
            throw new RepresentationNotAllowed("Method {$method->value} is not enabled in this configuration.");
        }
        $normalized = RepresentationScope::normalize($scopes);
        $outside = array_diff($normalized, config('identity.representation.grantable_scopes', []));
        if ($outside !== []) {
            throw new RepresentationNotAllowed('Scopes outside the allowed representation scope: '.implode(', ', $outside).'.');
        }
        $actor = $this->context->current();
        if ($this->actorPersonId() === $representative->id) {
            throw new RepresentationNotAllowed('Nobody establishes or extends a representation for themselves.');
        }

        return [
            'scopes' => $normalized,
            'method' => $method->value,
            'basis' => $basis,
            'established_by_type' => $actor->type->value,
            'established_by_id' => $actor->identifier,
        ];
    }

    private function actorPersonId(): ?int
    {
        $actor = $this->context->current();

        return $actor->type === ActorType::Account && $actor->identifier !== null
            ? User::query()->whereKey($actor->identifier)->value('person_id')
            : null;
    }
}
