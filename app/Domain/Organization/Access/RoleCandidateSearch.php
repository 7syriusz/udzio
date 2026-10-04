<?php

namespace App\Domain\Organization\Access;

use App\Domain\Identity\ContactMask;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\OrganizationHierarchy;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Choosing the account that is to receive a role (E3.7b, Z-037). Part of the right to grant that role — not
 * members.view: allowed only for a role in the manager's role-granting catalog and a unit where the manager may
 * grant it. Searches only current members of the units where the manager may grant this role — never the whole
 * account base — and returns minimal data (name, masked e-mail, account active, in the requested unit).
 * An e-mail matches only exactly and only inside that area, so the answer for "no such account" and
 * "an account outside your scope" is the same. Searches are limited per manager.
 */
final class RoleCandidateSearch
{
    public function __construct(
        private readonly AccessDecider $access,
        private readonly OrganizationHierarchy $hierarchy,
    ) {}

    /** @return list<RoleCandidate> */
    public function search(User $manager, AccessRole $role, Organization $scope, string $query): array
    {
        $this->throttle($manager);
        $query = trim($query);
        if (mb_strlen($query) < (int) config('organization.candidate_search.min_query_length')) {
            throw ValidationException::withMessages(['query' => __('organization.candidates.query_too_short', ['min' => config('organization.candidate_search.min_query_length')])]);
        }
        $units = $this->access->roleGrantCatalog($manager)[$role->public_id] ?? [];
        if (! in_array($scope->id, $units, true)) {
            $this->access->recordDenial('role_candidate', $scope->public_id, $scope->public_id, [
                'ability' => Permission::RolesAssign->value,
                'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'account_id' => $manager->getKey(),
                'granted_role' => $role->public_id,
                'decision' => 'denied',
                'reason' => 'role_not_grantable_here',
            ]);

            throw (new AccessDenied('role_candidate', $scope->public_id, $scope->public_id, Permission::RolesAssign->value))->alreadyRecorded();
        }

        $now = CarbonImmutable::now('UTC');
        $members = Membership::query()->select('person_id')->whereIn('organization_id', $units)->effectiveAt($now);
        $accounts = User::query()->whereIn('person_id', $members)->with('person');
        if (str_contains($query, '@')) {
            $accounts->where('email', mb_strtolower($query));
        } else {
            $accounts->whereHas('person', function ($people) use ($query): void {
                foreach (preg_split('/\s+/u', $query) as $word) {
                    $people->where(fn ($person) => $person->where('given_name', 'like', $this->prefix($word))->orWhere('family_name', 'like', $this->prefix($word)));
                }
            });
        }
        $found = $accounts->orderBy('id')->limit((int) config('organization.candidate_search.max_results'))->get();

        $requestedUnits = [$scope->id, ...$this->hierarchy->descendantsAt($scope, $now)->modelKeys()];
        $inRequested = Membership::query()->whereIn('person_id', $found->pluck('person_id'))->whereIn('organization_id', $requestedUnits)
            ->effectiveAt($now)->pluck('person_id')->unique()->all();
        $fullContacts = $this->access->decide($manager, Permission::PeopleContactsView, $scope, $now)->allowed;

        return $found->map(fn (User $account) => new RoleCandidate(
            $account->person->public_id,
            $account->person->fullName(),
            $fullContacts ? $account->email : ContactMask::email($account->email),
            ! $fullContacts,
            $account->email_verified_at !== null,
            in_array($account->person_id, $inRequested, true),
        ))->values()->all();
    }

    private function throttle(User $manager): void
    {
        $key = 'role-candidates:'.$manager->getKey();
        if (RateLimiter::tooManyAttempts($key, (int) config('organization.candidate_search.max_per_minute'))) {
            throw new ThrottleRequestsException('organization.candidates.too_many_searches', headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);
    }

    private function prefix(string $word): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';
    }
}
