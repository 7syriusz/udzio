<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Identity\Actions\ResolvePersonLinkReview;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\PersonLinkReview;
use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\PersonLinkReviewAccess;
use App\Domain\Organization\Access\PlatformAccess;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resolves an account-to-person review (Z-022) on behalf of an authorized operator (E3.9, Z-042): a platform
 * administrator with `platform.person_links.resolve`, or an organization operator with `person_links.resolve`
 * for the candidates they cover (PersonLinkReviewAccess). The decision is recorded like every access decision;
 * the linking itself is the Identity procedure (ResolvePersonLinkReview), which no HTTP path calls directly.
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
    ) {}

    public function handle(PersonLinkReview $review, ?Person $person, string $reason): PersonLinkReview
    {
        return $this->operation->within(fn () => DB::transaction(function () use ($review, $person, $reason): PersonLinkReview {
            $review = PersonLinkReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
            $actor = $this->context->current();
            $operator = $actor->type === ActorType::Account ? User::query()->find($actor->identifier) : null;
            if ($operator === null) {
                $this->deny($review, null, 'actor_without_account');
            }
            if ($this->reviews->isPlatformResolver($operator)) {
                $this->platform->authorize(PlatformPermission::PersonLinksResolve, $review->account);
            } else {
                $this->authorizeInScope($review, $person, $operator);
            }

            return $this->resolve->handle($review, $person, $reason);
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
            $unit = $covered[$person->id] ?? null;
            if ($unit === null) {
                $this->deny($review, $operator, 'candidate_outside_scope');
            }
            $units = [$unit];
        } else {
            // "A new person" means none of the candidates is the account holder: only someone who sees them all decides that.
            if (count($covered) !== count($review->candidate_person_ids)) {
                $this->deny($review, $operator, 'candidates_outside_scope');
            }
            $units = array_values(array_unique($covered));
        }
        foreach (Organization::query()->whereKey($units)->orderBy('id')->get() as $unit) {
            $this->decider->authorize(Permission::PersonLinksResolve, $unit, 'person_link_review', $review->public_id);
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
        $denied = (new AccessDenied('person_link_review', $review->public_id, null, Permission::PersonLinksResolve->value))->alreadyRecorded();

        throw $hide ? $denied->hideAsNotFound() : $denied;
    }
}
