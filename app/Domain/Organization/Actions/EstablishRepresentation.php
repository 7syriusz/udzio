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
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Establishes a representation by an authorized role (Z-025, E3.9, E3.9a, Z-042) — only under a representation
 * policy that a scenario or the organization's configuration provides (`organization.representation_policies`):
 * who may be represented, on which ground and after checking what, for how long and with which scopes. An
 * employee never establishes one because it seems useful. The operator needs `representations.establish` in a
 * unit where the represented person is a current member matching the policy; a person outside the scope is
 * answered as not found. Identity rules still apply (enabled methods, grantable scopes, never for oneself).
 */
final class EstablishRepresentation
{
    /** Grounds a policy may name: a checked document, a decision, or a declaration the policy accepts (Z-042). */
    public const POLICY_METHODS = [RepresentationMethod::Document, RepresentationMethod::RoleDecision, RepresentationMethod::Declaration];

    public function __construct(
        private readonly GrantRepresentation $grant,
        private readonly AccessDecider $decider,
        private readonly ActorContext $context,
        private readonly OperationCorrelation $operation,
        private readonly AuditReason $reason,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  string  $policy  key of `organization.representation_policies`
     * @param  list<RepresentationScope>  $scopes
     * @param  string  $checkedDocument  the document or ground that was checked (recorded as the representation's basis)
     */
    public function handle(string $policy, Person $representative, Person $represented, array $scopes, string $checkedDocument, DateTimeInterface $from, ?DateTimeInterface $until, string $reason): Representation
    {
        $rules = $this->policy($policy);
        $from = CarbonImmutable::instance($from)->utc();
        $until = $until === null ? null : CarbonImmutable::instance($until)->utc();
        $this->checkRequest($rules, $scopes, $checkedDocument, $from, $until);

        return $this->operation->within(fn () => DB::transaction(function () use ($policy, $rules, $representative, $represented, $scopes, $checkedDocument, $from, $until, $reason): Representation {
            $unit = $this->unitOf($represented, $rules);
            $this->decider->authorize(Permission::RepresentationsEstablish, $unit, 'person', $represented->public_id);
            $representation = $this->grant->handle($representative, $represented, $scopes, RepresentationMethod::from($rules['method']), trim($checkedDocument), $from, $reason);
            if ($until !== null) {
                $representation = $this->reason->because($reason, fn () => $representation->end($until));
            }
            $this->audit->handle('representation.established_by_role', 'person', $represented->public_id, organizationId: $unit->public_id, reason: $reason, after: [
                'policy' => $policy,
                'method' => $rules['method'],
                'checked_document' => trim($checkedDocument),
                'scopes' => RepresentationScope::normalize($scopes),
                'until' => $until?->format('Y-m-d H:i:s.u'),
            ]);

            return $representation;
        }));
    }

    /** @return array<string, mixed> */
    private function policy(string $policy): array
    {
        $rules = config('organization.representation_policies.'.$policy);
        if (! is_array($rules)) {
            throw ValidationException::withMessages(['policy' => __('organization.validation.representation_policy_missing')]);
        }
        if (! in_array($rules['method'] ?? null, array_map(fn (RepresentationMethod $method) => $method->value, self::POLICY_METHODS), true)) {
            throw new LogicException('A representation policy for a role uses a checked document, a decision or an accepted declaration.');
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<RepresentationScope>  $scopes
     */
    private function checkRequest(array $rules, array $scopes, string $checkedDocument, CarbonImmutable $from, ?CarbonImmutable $until): void
    {
        if (trim($checkedDocument) === '') {
            throw ValidationException::withMessages(['checked_document' => __('organization.validation.representation_document_required')]);
        }
        if (array_diff(RepresentationScope::normalize($scopes), $rules['scopes'] ?? []) !== []) {
            throw ValidationException::withMessages(['scopes' => __('organization.validation.representation_scope_not_allowed')]);
        }
        $maxDays = $rules['max_days'] ?? null;
        if ($maxDays !== null && ($until === null || $until->greaterThan($from->addDays($maxDays)))) {
            throw ValidationException::withMessages(['until' => __('organization.validation.representation_until_required', ['days' => $maxDays])]);
        }
    }

    /**
     * A unit in the operator's scope (and allowed by the policy) where the represented person is a current member
     * with a function the policy covers; otherwise not found.
     *
     * @param  array<string, mixed>  $rules
     */
    private function unitOf(Person $represented, array $rules): Organization
    {
        $actor = $this->context->current();
        $operator = $actor->type === ActorType::Account ? User::query()->find($actor->identifier) : null;
        $memberships = Membership::query()->where('person_id', $represented->id)->activeAt(CarbonImmutable::now('UTC'))->orderBy('id')
            ->when(($rules['represented']['functions'] ?? null) !== null, fn ($query) => $query->whereIn('function', $rules['represented']['functions']))
            ->with('organization')->get()
            ->filter(fn (Membership $membership) => ($rules['organizations'] ?? null) === null || in_array($membership->organization->public_id, $rules['organizations'], true));
        $memberOf = $memberships->pluck('organization_id')->unique()->values()->all();
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
