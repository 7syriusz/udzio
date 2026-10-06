<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Identity\Actions\ResolvePersonLinkReview;
use App\Domain\Identity\Enums\PersonLinkBasis;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\PersonLinkReview;
use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\PersonLinkReviewAccess;
use App\Domain\Organization\Access\PlatformAccess;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Resolves an account-to-person review (Z-022) on behalf of an authorized operator (E3.9, E3.9a, Z-042): a
 * platform administrator with `platform.person_links.resolve`, or an organization operator with
 * `person_links.resolve` for the candidates they cover (PersonLinkReviewAccess). Every resolution — also by a
 * platform administrator, also "a new person" — needs an accepted ground (PersonLinkBasis; similar names or
 * contact data are not one), a description of the evidence and a reason; without them the review stays open.
 * Audited as `person_link_review.resolved`; the linking itself is the Identity procedure (ResolvePersonLinkReview),
 * which no HTTP path calls directly.
 */
final class ResolvePersonLinkReviewAsOperator
{
    public function __construct(
        private readonly ResolvePersonLinkReview $resolve,
        private readonly PersonLinkReviewAccess $reviews,
        private readonly AccessDecider $decider,
        private readonly PlatformAccess $platform,
        private readonly ActorContext $context,
        private readonly OperationCorrelation $operation,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  string  $basis  a PersonLinkBasis value
     * @param  string  $evidence  what was checked (e.g. "dowód osobisty sprawdzony w biurze", reference of a confirmation)
     */
    public function handle(PersonLinkReview $review, ?Person $person, string $basis, string $evidence, string $reason): PersonLinkReview
    {
        $input = Validator::make(['basis' => $basis, 'evidence' => trim($evidence), 'reason' => trim($reason)], [
            'basis' => ['required', Rule::enum(PersonLinkBasis::class)],
            'evidence' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return $this->operation->within(fn () => DB::transaction(function () use ($review, $person, $input): PersonLinkReview {
            $review = PersonLinkReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
            $actor = $this->context->current();
            $operator = $actor->type === ActorType::Account ? User::query()->find($actor->identifier) : null;
            if ($operator === null) {
                $this->deny($review, null, 'actor_without_account');
            }
            $level = $this->reviews->isPlatformResolver($operator) ? 'platform' : 'organization';
            if ($level === 'platform') {
                $this->platform->authorize(PlatformPermission::PersonLinksResolve, $review->account);
            } else {
                $this->authorizeInScope($review, $person, $operator);
            }
            $resolved = $this->resolve->handle($review, $person, $input['reason']);
            $this->audit->handle('person_link_review.resolved', 'person_link_review', $review->public_id, reason: $input['reason'], after: [
                'level' => $level,
                'basis' => $input['basis'],
                'evidence' => $input['evidence'],
                'linked_person' => $resolved->fresh()->resolved_person_id === null ? null : Person::query()->whereKey($resolved->fresh()->resolved_person_id)->value('public_id'),
                'new_person' => $person === null,
            ]);

            return $resolved;
        }));
    }

    private function authorizeInScope(PersonLinkReview $review, ?Person $person, User $operator): void
    {
        if ($review->user_id === $operator->id) {
            $this->deny($review, $operator, 'own_review');
        }
        $covered = $this->reviews->coveredCandidates($operator, $review);
        if ($covered === []) {
            $candidateUnits = Membership::query()->whereIn('person_id', $review->candidate_person_ids)->activeAt(CarbonImmutable::now('UTC'))->pluck('organization_id')->all();
            $blocked = $this->decider->blockedBySecurityCondition($operator, Permission::PersonLinksResolve, $candidateUnits);
            if ($blocked !== null) {
                // Refused with the guidance to verify the e-mail or set up MFA (E3.8a).
                $this->decider->authorize(Permission::PersonLinksResolve, $blocked, 'person_link_review', $review->public_id);
            }
            $this->deny($review, $operator, 'no_candidate_in_scope', hide: true);
        }
        if ($person !== null) {
            if (! isset($covered[$person->id])) {
                $this->deny($review, $operator, 'candidate_outside_scope');
            }
            $covered = [$person->id => $covered[$person->id]];
        } else {
            // "A new person" means none of the candidates is the account holder: only someone who sees them all decides that.
            if (count($covered) !== count($review->candidate_person_ids)) {
                $this->deny($review, $operator, 'candidates_outside_scope');
            }
        }
        foreach ($covered as $coverage) {
            $unit = Organization::query()->findOrFail($coverage['unit']);
            if ($unit->isActiveAt(CarbonImmutable::now('UTC'))) {
                $this->decider->authorize(Permission::PersonLinksResolve, $unit, 'person_link_review', $review->public_id);
            } else {
                // An archived unit is reached only through today's history rights (PersonLinkReviewAccess).
                $this->audit->handle('access.granted', 'person_link_review', $review->public_id, organizationId: $unit->public_id, after: [
                    'permission' => Permission::PersonLinksResolve->value, 'account_id' => $operator->getKey(), 'via' => $coverage['via'], 'decision' => 'allowed', 'reason' => 'history_rights',
                ]);
            }
        }
    }

    private function deny(PersonLinkReview $review, ?User $operator, string $reason, bool $hide = false): never
    {
        $this->decider->recordDenial('person_link_review', $review->public_id, null, [
            'permission' => Permission::PersonLinksResolve->value,
            'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'account_id' => $operator?->getKey(),
            'decision' => 'denied',
            'reason' => $reason,
        ]);
        $denied = (new AccessDenied('person_link_review', $review->public_id, null, Permission::PersonLinksResolve->value, AccessDenied::messageFor($reason)))->alreadyRecorded();

        throw $hide ? $denied->hideAsNotFound() : $denied;
    }
}
