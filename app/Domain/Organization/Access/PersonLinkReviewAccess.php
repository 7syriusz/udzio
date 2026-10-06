<?php

namespace App\Domain\Organization\Access;

use App\Domain\Identity\Enums\PersonLinkReviewStatus;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\PersonLinkReview;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Organization\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who may see and resolve an account-to-person review (Z-022, E3.9, Z-042). An organization operator with
 * `person_links.resolve` covers the candidates who are current members of units in their scope: they see only
 * those, may link the account to one of them, and may choose "a new person" only when they cover every
 * candidate (otherwise someone outside their view could be the account holder). A platform administrator with
 * `platform.person_links.resolve` covers every candidate. Nobody resolves the review of their own account.
 */
final class PersonLinkReviewAccess
{
    public function __construct(
        private readonly AccessDecider $decider,
        private readonly PlatformAccess $platform,
    ) {}

    public function isPlatformResolver(User $operator): bool
    {
        return $this->platform->decide($operator, PlatformPermission::PersonLinksResolve)->allowed;
    }

    /**
     * Candidates the operator covers through a current membership in their scope.
     *
     * @return array<int, int> candidate person ID => ID of a unit in scope where the candidate is a member
     */
    public function coveredCandidates(User $operator, PersonLinkReview $review): array
    {
        $units = $this->decider->grantedOrganizationIds($operator, Permission::PersonLinksResolve);
        if ($units === []) {
            return [];
        }

        return Membership::query()->whereIn('person_id', $review->candidate_person_ids)->whereIn('organization_id', $units)
            ->activeAt(CarbonImmutable::now('UTC'))->orderBy('id')->get()
            ->unique('person_id')->mapWithKeys(fn (Membership $membership) => [$membership->person_id => $membership->organization_id])->all();
    }

    /**
     * Minimal data of the candidates the operator may see (identifier, given and family name) and whether they
     * cover all of them — only then may "a new person" be chosen. The account holder never sees this.
     *
     * @return array{candidates: Collection<int, array{public_id: string, given_name: string, family_name: string}>, covers_all: bool}
     */
    public function candidatesFor(User $operator, PersonLinkReview $review): array
    {
        $ids = $this->isPlatformResolver($operator) ? $review->candidate_person_ids : array_keys($this->coveredCandidates($operator, $review));
        if ($review->user_id === $operator->id) {
            $ids = [];
        }
        $candidates = Person::query()->whereKey($ids)->orderBy('family_name')->orderBy('given_name')->get()
            ->map(fn (Person $person) => ['public_id' => $person->public_id, 'given_name' => $person->given_name, 'family_name' => $person->family_name]);

        return ['candidates' => $candidates, 'covers_all' => $ids !== [] && count($ids) === count($review->candidate_person_ids)];
    }

    /**
     * Open reviews the operator can act on: all for a platform resolver, otherwise those with at least one
     * covered candidate; never the review of the operator's own account.
     *
     * @return Collection<int, PersonLinkReview>
     */
    public function openReviewsFor(User $operator): Collection
    {
        $open = PersonLinkReview::query()->where('status', PersonLinkReviewStatus::Open)->where('user_id', '!=', $operator->id)->orderBy('id')->get();
        if ($this->isPlatformResolver($operator)) {
            return $open;
        }

        return $open->filter(fn (PersonLinkReview $review) => $this->coveredCandidates($operator, $review) !== [])->values();
    }
}
