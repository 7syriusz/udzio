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
 * Who may see and resolve an account-to-person review (Z-022, E3.9, E3.9a, Z-042). An organization operator with
 * `person_links.resolve` covers the candidates who are current members of units in their scope, and former
 * members of units where they also hold — today — `members.history.view` (with the same retention limit as
 * membership history). A role the operator held in the past gives nothing. They see only covered candidates,
 * may link the account to one of them, and may choose "a new person" only when they cover every candidate;
 * otherwise the case needs a higher level. A platform administrator with `platform.person_links.resolve` covers
 * every candidate. Nobody resolves the review of their own account.
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
     * Candidates the operator covers today: current members of units in their `person_links.resolve` scope, then
     * former members of units where they also hold `members.history.view` today.
     *
     * @return array<int, array{unit: int, via: 'membership'|'membership_history'}> candidate person ID => covering unit
     */
    public function coveredCandidates(User $operator, PersonLinkReview $review): array
    {
        $now = CarbonImmutable::now('UTC');
        $covered = [];
        $units = $this->decider->grantedOrganizationIds($operator, Permission::PersonLinksResolve);
        if ($units !== []) {
            foreach (Membership::query()->whereIn('person_id', $review->candidate_person_ids)->whereIn('organization_id', $units)->activeAt($now)->orderBy('id')->get() as $membership) {
                $covered[$membership->person_id] ??= ['unit' => $membership->organization_id, 'via' => 'membership'];
            }
        }
        $historyUnits = array_values(array_intersect(
            $this->decider->historyOrganizationIds($operator, Permission::MembersHistoryView),
            $this->decider->historyOrganizationIds($operator, Permission::PersonLinksResolve),
        ));
        if ($historyUnits !== []) {
            $former = Membership::query()->whereIn('person_id', $review->candidate_person_ids)->whereIn('organization_id', $historyUnits)
                ->whereNotNull('valid_to')->where('valid_to', '<=', $now->format('Y-m-d H:i:s.u'));
            $days = config('organization.history.visible_days');
            if ($days !== null) {
                $former->where('valid_to', '>=', $now->subDays((int) $days)->format('Y-m-d H:i:s.u'));
            }
            foreach ($former->orderBy('id')->get() as $membership) {
                $covered[$membership->person_id] ??= ['unit' => $membership->organization_id, 'via' => 'membership_history'];
            }
        }

        return $covered;
    }

    /**
     * Minimal data of the candidates the operator may see (identifier, given and family name), whether they cover
     * all of them — only then may "a new person" be chosen — and, if not, only the fact that the case needs a
     * higher level (`escalation_notice`, never data of the others). The account holder never sees this.
     *
     * @return array{candidates: Collection<int, array{public_id: string, given_name: string, family_name: string}>, covers_all: bool, escalation_notice: ?string}
     */
    public function candidatesFor(User $operator, PersonLinkReview $review): array
    {
        $ids = $this->isPlatformResolver($operator) ? $review->candidate_person_ids : array_keys($this->coveredCandidates($operator, $review));
        if ($review->user_id === $operator->id) {
            $ids = [];
        }
        $candidates = Person::query()->whereKey($ids)->orderBy('family_name')->orderBy('given_name')->get()
            ->map(fn (Person $person) => ['public_id' => $person->public_id, 'given_name' => $person->given_name, 'family_name' => $person->family_name]);

        $coversAll = $ids !== [] && count($ids) === count($review->candidate_person_ids);

        return ['candidates' => $candidates, 'covers_all' => $coversAll, 'escalation_notice' => $ids !== [] && ! $coversAll ? __('access.escalation_required') : null];
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
