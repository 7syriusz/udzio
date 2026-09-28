<?php

namespace App\Domain\Organization\Access;

use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\Representation;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data isolation (A5-01, A5-14, §1.1; E3.7, revised in E3.7a after the E3.6 review). What an account may list
 * and read, always derived from AccessDecider — never from own scope logic:
 * - organization data only in units granted by the operational permission (`organization.view`, `members.view`);
 * - role data separately from operational data (Z-035): role assignments only for roles in the account's
 *   role-granting catalog, in the units where it may grant them, plus the account's own assignments;
 * - a PERSON is global but visible only through a context (own person, represented with `profile.view`,
 *   members of units with `members.view`).
 * Pending assignments grant nothing. Every method accepts a moment, so what was visible can be reconstructed.
 * Reading something outside answers a generic 404; the denial goes through AccessDecider (one entry per operation).
 */
final class DataVisibility
{
    public function __construct(private readonly AccessDecider $access) {}

    /** @return Builder<Organization> */
    public function organizations(User $account, Permission $permission = Permission::OrganizationView, ?DateTimeInterface $at = null): Builder
    {
        return Organization::query()->whereKey($this->access->grantedOrganizationIds($account, $permission, $at));
    }

    /**
     * Membership periods (current and past) in the units where the account holds members.view.
     *
     * @return Builder<Membership>
     */
    public function memberships(User $account, ?DateTimeInterface $at = null): Builder
    {
        return Membership::query()->whereIn('organization_id', $this->access->grantedOrganizationIds($account, Permission::MembersView, $at));
    }

    /** @return Builder<Person> */
    public function people(User $account, ?DateTimeInterface $at = null): Builder
    {
        $memberUnits = $this->access->grantedOrganizationIds($account, Permission::MembersView, $at);
        $represented = $this->representedPersonIds($account, $at);

        return Person::query()->where(function (Builder $query) use ($account, $memberUnits, $represented): void {
            $query->whereKey([...$represented, ...($account->person_id === null ? [] : [$account->person_id])])
                ->orWhereIn('id', Membership::query()->select('person_id')->whereIn('organization_id', $memberUnits));
        });
    }

    /**
     * Role assignments (active, pending, past) the account may see: its own, and those of roles in its
     * role-granting catalog in the units where it may grant them. Other roles' assignments stay hidden.
     *
     * @return Builder<RoleAssignment>
     */
    public function roleAssignments(User $account, ?DateTimeInterface $at = null): Builder
    {
        $catalog = $this->access->roleGrantCatalog($account, $at);
        $roleIds = AccessRole::query()->whereIn('public_id', array_keys($catalog))->pluck('id', 'public_id');

        return RoleAssignment::query()->where(function (Builder $query) use ($account, $catalog, $roleIds): void {
            $query->where('user_id', $account->getKey());
            foreach ($catalog as $rolePublicId => $units) {
                $query->orWhere(fn (Builder $grantable) => $grantable->where('access_role_id', $roleIds[$rolePublicId])->whereIn('scope_organization_id', $units));
            }
        });
    }

    /**
     * Role definitions the account may see: all roles of units where it holds roles.manage, roles in its
     * role-granting catalog, and roles it holds itself.
     *
     * @return Builder<AccessRole>
     */
    public function accessRoles(User $account, ?DateTimeInterface $at = null): Builder
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now('UTC'));
        $managedUnits = $this->access->grantedOrganizationIds($account, Permission::RolesManage, $at);
        $held = RoleAssignment::query()->select('access_role_id')->where('user_id', $account->getKey())->activeAt($at);

        return AccessRole::query()->where(fn (Builder $query) => $query
            ->whereIn('organization_id', $managedUnits)
            ->orWhereIn('public_id', array_keys($this->access->roleGrantCatalog($account, $at)))
            ->orWhereIn('id', $held));
    }

    public function findOrganization(User $account, string $publicId, Permission $permission = Permission::OrganizationView): Organization
    {
        return $this->organizations($account, $permission)->where('public_id', $publicId)->first()
            ?? $this->notFound($account, 'organization', $publicId, $permission->value);
    }

    public function findPerson(User $account, string $publicId): Person
    {
        return $this->people($account)->where('public_id', $publicId)->first()
            ?? $this->notFound($account, 'person', $publicId, 'person.view');
    }

    public function findRoleAssignment(User $account, string $publicId): RoleAssignment
    {
        return $this->roleAssignments($account)->where('public_id', $publicId)->first()
            ?? $this->notFound($account, 'role_assignment', $publicId, 'role_assignment.view');
    }

    /** @return list<int> */
    private function representedPersonIds(User $account, ?DateTimeInterface $at): array
    {
        if ($account->person_id === null) {
            return [];
        }

        return Representation::query()->where('representative_person_id', $account->person_id)
            ->activeAt($at ?? CarbonImmutable::now('UTC'))->get()
            ->filter(fn (Representation $representation) => $representation->allows(RepresentationScope::ProfileView))
            ->pluck('represented_person_id')->all();
    }

    private function notFound(User $account, string $subjectType, string $publicId, string $ability): never
    {
        $this->access->recordDenial($subjectType, $publicId, null, [
            'ability' => $ability,
            'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'account_id' => $account->getKey(),
            'decision' => 'denied',
            'reason' => 'not_visible',
        ]);

        throw (new AccessDenied($subjectType, $publicId, null, $ability))->alreadyRecorded()->hideAsNotFound();
    }
}
