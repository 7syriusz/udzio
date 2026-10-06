<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Identity\Actions\GrantRepresentation;
use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\Representation;
use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Establishes a representation by an authorized role (Z-025, E3.9, Z-042): the operator needs
 * `representations.establish` in a unit where the represented person is a current member. A person outside the
 * operator's scope is answered as not found. The role records its decision (`role_decision`) or the document it
 * checked (`document`); acceptance and declaration come from the parties, not from a role. The Identity rules
 * still apply (enabled methods, grantable scopes, nobody establishes a representation for themselves).
 */
final class EstablishRepresentation
{
    public const ROLE_METHODS = [RepresentationMethod::RoleDecision, RepresentationMethod::Document];

    public function __construct(
        private readonly GrantRepresentation $grant,
        private readonly AccessDecider $decider,
        private readonly ActorContext $context,
        private readonly OperationCorrelation $operation,
    ) {}

    /** @param list<RepresentationScope> $scopes */
    public function handle(Person $representative, Person $represented, array $scopes, RepresentationMethod $method, string $basis, DateTimeInterface $from, string $reason): Representation
    {
        if (! in_array($method, self::ROLE_METHODS, true)) {
            throw new InvalidArgumentException('A role establishes a representation by its decision or a checked document.');
        }

        return $this->operation->within(fn () => DB::transaction(function () use ($representative, $represented, $scopes, $method, $basis, $from, $reason): Representation {
            $this->decider->authorize(Permission::RepresentationsEstablish, $this->unitOf($represented), 'person', $represented->public_id);

            return $this->grant->handle($representative, $represented, $scopes, $method, $basis, $from, $reason);
        }));
    }

    /** A unit in the operator's scope where the represented person is a current member; otherwise not found. */
    private function unitOf(Person $represented): Organization
    {
        $actor = $this->context->current();
        $operator = $actor->type === ActorType::Account ? User::query()->find($actor->identifier) : null;
        $memberOf = Membership::query()->where('person_id', $represented->id)->activeAt(CarbonImmutable::now('UTC'))->orderBy('id')->pluck('organization_id')->all();
        $inScope = $operator === null ? [] : array_values(array_intersect($memberOf, $this->decider->grantedOrganizationIds($operator, Permission::RepresentationsEstablish)));
        if ($inScope !== []) {
            return Organization::query()->findOrFail($inScope[0]);
        }
        $blocked = $operator === null ? null : $this->decider->blockedBySecurityCondition($operator, Permission::RepresentationsEstablish, $memberOf);
        if ($blocked !== null) {
            // authorize() refuses it with the guidance to verify the e-mail or set up MFA (E3.8a).
            return $blocked;
        }
        if ($operator === null && $memberOf !== []) {
            // A technical process is judged by its declared purpose (SystemAuthority) in authorize().
            return Organization::query()->findOrFail($memberOf[0]);
        }
        $this->decider->recordDenial('person', $represented->public_id, null, [
            'permission' => Permission::RepresentationsEstablish->value,
            'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'account_id' => $operator?->getKey(),
            'decision' => 'denied',
            'reason' => 'represented_outside_scope',
        ]);

        throw (new AccessDenied('person', $represented->public_id, null, Permission::RepresentationsEstablish->value))->alreadyRecorded()->hideAsNotFound();
    }
}
