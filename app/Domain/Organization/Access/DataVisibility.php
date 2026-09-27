<?php

namespace App\Domain\Organization\Access;

use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\Representation;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Actions\RecordAccessDenial;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Data isolation (A5-01, A5-14, §1.1): what an account may list and read. Organization data is limited
 * to the units granted by AccessDecider; a PERSON is global but visible only through a context — the
 * account's own person, people it represents (profile.view), and members of units where it holds
 * members.view. Reading something outside answers 404 and records the denial.
 */
final class DataVisibility
{
    public function __construct(private readonly AccessDecider $access, private readonly RecordAccessDenial $denials) {}

    /** @return Builder<Organization> */
    public function organizations(User $account, Permission $permission = Permission::OrganizationView): Builder
    {
        return Organization::query()->whereKey($this->access->grantedOrganizationIds($account, $permission));
    }

    /**
     * Membership periods (current and past) in the units where the account holds members.view.
     *
     * @return Builder<Membership>
     */
    public function memberships(User $account): Builder
    {
        return Membership::query()->whereIn('organization_id', $this->access->grantedOrganizationIds($account, Permission::MembersView));
    }

    /** @return Builder<Person> */
    public function people(User $account): Builder
    {
        $memberUnits = $this->access->grantedOrganizationIds($account, Permission::MembersView);
        $represented = $this->representedPersonIds($account);

        return Person::query()->where(function (Builder $query) use ($account, $memberUnits, $represented): void {
            $query->whereKey([...$represented, ...($account->person_id === null ? [] : [$account->person_id])])
                ->orWhereIn('id', Membership::query()->select('person_id')->whereIn('organization_id', $memberUnits));
        });
    }

    public function findOrganization(User $account, string $publicId, Permission $permission = Permission::OrganizationView): Organization
    {
        return $this->organizations($account, $permission)->where('public_id', $publicId)->first()
            ?? $this->notFound('organization', $publicId, $permission->value);
    }

    public function findPerson(User $account, string $publicId): Person
    {
        return $this->people($account)->where('public_id', $publicId)->first()
            ?? $this->notFound('person', $publicId, 'person.view');
    }

    /** @return list<int> */
    private function representedPersonIds(User $account): array
    {
        if ($account->person_id === null) {
            return [];
        }

        return Representation::query()->where('representative_person_id', $account->person_id)
            ->activeAt(CarbonImmutable::now('UTC'))->get()
            ->filter(fn (Representation $representation) => $representation->allows(RepresentationScope::ProfileView))
            ->pluck('represented_person_id')->all();
    }

    private function notFound(string $subjectType, string $publicId, string $ability): never
    {
        try {
            $this->denials->handle($subjectType, $publicId, null, ['ability' => $ability, 'reason' => 'not_visible']);
        } catch (Throwable $failure) {
            Log::critical('Access denial could not be audited.', ['exception' => $failure::class, 'message' => $failure->getMessage()]);
        }

        throw (new AccessDenied($subjectType, $publicId, null, $ability))->alreadyRecorded()->hideAsNotFound();
    }
}
