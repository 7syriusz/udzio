<?php

namespace App\Domain\Organization\Access;

use App\Domain\Identity\ContactMask;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\Representation;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actions\RecordDataAccess;
use App\Domain\Platform\Actions\RecordProtectedRead;
use App\Domain\Platform\Enums\RelationStatus;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Data isolation (A5-01, A5-14, §1.1; E3.7, revised in E3.7a and E3.7b). What an account may list and read,
 * always derived from AccessDecider — never from own scope logic:
 * - organization data only in units granted by the operational permission (`organization.view`, `members.view`);
 *   `members.view` shows current members only — former members need `members.history.view`;
 * - role data separately from operational data (Z-035): role assignments only for roles in the account's
 *   role-granting catalog, in the units where it may grant them, plus the account's own assignments; all
 *   assignments of a unit only with `roles.audit.view`;
 * - a PERSON is global but visible only through a context (own person, represented with `profile.view`,
 *   members of units with `members.view`).
 * History (a past moment, former members, archived units) is shown only when the account has the right to
 * that kind of history today; only then the structure of the chosen moment decides which units were under the
 * scope of today's assignment. Roles held at that moment play no part: a role held in the past gives nothing
 * today, and a right granted today covers the past as well. Pending assignments grant nothing. Reading something outside answers a generic 404; the
 * denial goes through AccessDecider (one entry per operation). Exports, bulk reads, protected reads and reads
 * by a technical process are audited; ordinary list views are not.
 */
final class DataVisibility
{
    public function __construct(
        private readonly AccessDecider $access,
        private readonly SystemAuthority $system,
        private readonly RecordDataAccess $reads,
        private readonly RecordProtectedRead $protectedReads,
    ) {}

    /** @return Builder<Organization> */
    public function organizations(User $account, Permission $permission = Permission::OrganizationView, ?DateTimeInterface $at = null): Builder
    {
        $ids = $this->isPast($at)
            ? $this->historyUnits($account, Permission::StructureHistoryView, 'organization', $at)
            : $this->access->grantedOrganizationIds($account, $permission);

        return Organization::query()->whereKey(array_values($ids));
    }

    /**
     * Current members (membership periods in force at the moment) of units where the account holds
     * members.view. A past moment requires members.history.view today.
     *
     * @return Builder<Membership>
     */
    public function memberships(User $account, ?DateTimeInterface $at = null): Builder
    {
        $units = $this->isPast($at)
            ? $this->historyUnits($account, Permission::MembersHistoryView, 'membership', $at)
            : $this->access->grantedOrganizationIds($account, Permission::MembersView);

        return Membership::query()->whereIn('organization_id', array_values($units))->effectiveAt($at ?? CarbonImmutable::now('UTC'));
    }

    /**
     * All membership periods — current and ended, also of archived units that belonged to the scope — for
     * holders of members.history.view (E3.7b). Ended periods older than `organization.history.visible_days`
     * are hidden when that limit is set (retention policy, Z-017); nothing is deleted.
     *
     * @return Builder<Membership>
     */
    public function membershipHistory(User $account): Builder
    {
        $query = Membership::query()->whereIn('organization_id', $this->historyUnits($account, Permission::MembersHistoryView, 'membership'));
        $days = config('organization.history.visible_days');
        if ($days !== null) {
            $limit = CarbonImmutable::now('UTC')->subDays((int) $days)->format('Y-m-d H:i:s.u');
            $query->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $limit));
        }

        return $query;
    }

    /** @return Builder<Person> */
    public function people(User $account, ?DateTimeInterface $at = null): Builder
    {
        $own = $account->person_id === null ? [] : [$account->person_id];
        if ($this->isPast($at)) {
            return Person::query()->where(fn (Builder $query) => $query->whereKey($own)
                ->orWhereIn('id', $this->memberships($account, $at)->select('person_id')));
        }
        $members = $this->memberships($account)->select('person_id');
        $formerMembers = $this->access->historyOrganizationIds($account, Permission::MembersHistoryView) === []
            ? null : $this->membershipHistory($account)->select('person_id');

        return Person::query()->where(function (Builder $query) use ($account, $own, $members, $formerMembers): void {
            $query->whereKey([...$own, ...$this->representedPersonIds($account)])->orWhereIn('id', $members);
            if ($formerMembers !== null) {
                $query->orWhereIn('id', $formerMembers);
            }
        });
    }

    /**
     * Role assignments (active, pending, past) the account may see: its own, those of roles in its
     * role-granting catalog in the units where it may grant them, and all of units where it holds
     * roles.audit.view. Other roles' assignments stay hidden. A past moment requires roles.audit.view today.
     *
     * @return Builder<RoleAssignment>
     */
    public function roleAssignments(User $account, ?DateTimeInterface $at = null): Builder
    {
        if (! $this->isPast($at)) {
            return $this->currentRoleAssignments($account);
        }

        return RoleAssignment::query()->whereIn('scope_organization_id', $this->historyUnits($account, Permission::RolesAuditView, 'role_assignment', $at));
    }

    /**
     * Role definitions the account may see: all roles of units where it holds roles.manage or roles.audit.view,
     * roles in its role-granting catalog, and roles it holds itself.
     *
     * @return Builder<AccessRole>
     */
    public function accessRoles(User $account, ?DateTimeInterface $at = null): Builder
    {
        if ($this->isPast($at)) {
            return AccessRole::query()->whereIn('organization_id', $this->historyUnits($account, Permission::RolesAuditView, 'access_role', $at));
        }
        $managedUnits = [
            ...$this->access->grantedOrganizationIds($account, Permission::RolesManage),
            ...$this->access->grantedOrganizationIds($account, Permission::RolesAuditView),
        ];
        $held = RoleAssignment::query()->select('access_role_id')->where('user_id', $account->getKey())->activeAt(CarbonImmutable::now('UTC'));

        return AccessRole::query()->where(fn (Builder $query) => $query
            ->whereIn('organization_id', $managedUnits)
            ->orWhereIn('public_id', array_keys($this->access->roleGrantCatalog($account)))
            ->orWhereIn('id', $held));
    }

    /**
     * Pending role requests the account may decide on, with only the data needed to decide (E3.7b).
     *
     * @return list<RoleRequestSummary>
     */
    public function pendingRoleRequests(User $approver): array
    {
        $catalog = $this->access->roleGrantCatalog($approver);
        $roleIds = AccessRole::query()->whereIn('public_id', array_keys($catalog))->pluck('id', 'public_id');
        $pending = RoleAssignment::query()->where('status', RelationStatus::Pending->value)
            ->where(function (Builder $query) use ($catalog, $roleIds): void {
                $query->whereRaw('1 = 0');
                foreach ($catalog as $rolePublicId => $units) {
                    $query->orWhere(fn (Builder $grantable) => $grantable->where('access_role_id', $roleIds[$rolePublicId])->whereIn('scope_organization_id', $units));
                }
            })->with(['role', 'scopeOrganization', 'account.person'])->orderBy('id')->get();

        $summaries = [];
        foreach ($pending as $request) {
            $decision = $this->access->decideRoleGrant($approver, 'approve', $request->role, $request->scopeOrganization, $request->requested_until, $request->user_id, $request->requested_by_type === 'account' ? $request->requested_by_id : null);
            if ($decision->denied()) {
                continue;
            }
            $created = AuditEntry::query()->where(['action' => 'role_assignment.created', 'subject_type' => 'role_assignment', 'subject_id' => (string) $request->id])->first();
            $requester = $request->requested_by_type === 'account' ? User::query()->find($request->requested_by_id) : null;
            $summaries[] = new RoleRequestSummary(
                $request->public_id,
                $request->account->person?->fullName() ?? trim($request->account->given_name.' '.$request->account->family_name),
                ContactMask::email($request->account->email),
                $request->role->name,
                $request->scopeOrganization->name,
                $request->scope_inheritance->value,
                $request->requested_until,
                $requester === null ? $request->requested_by_type : trim($requester->given_name.' '.$requester->family_name),
                $created?->occurred_at,
                $created?->reason,
            );
        }

        return $summaries;
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

    /**
     * Reads specially protected fields of a person: own data, or people.protected.view in a unit the person is
     * a current member of. Every read is audited (field names and purpose, never values).
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function readProtected(User $account, Person $person, array $fields, string $purpose): array
    {
        $units = $this->access->grantedOrganizationIds($account, Permission::PeopleProtectedView);
        $isOwn = $account->person_id !== null && $account->person_id === $person->id;
        if (! $isOwn && ! Membership::query()->where('person_id', $person->id)->whereIn('organization_id', $units)->effectiveAt(CarbonImmutable::now('UTC'))->exists()) {
            $this->notFound($account, 'person', $person->public_id, Permission::PeopleProtectedView->value);
        }
        $this->protectedReads->handle('person', $person->public_id, $fields, null, $purpose);

        return $person->only($fields);
    }

    /**
     * Export of data of one unit (data.export, decided and recorded by AccessDecider); the export itself is
     * audited with the number of rows, the fields and the purpose.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  already limited by this class
     * @param  list<string>  $fields
     * @return Collection<int, TModel>
     */
    public function export(User $account, Organization $unit, Builder $query, string $subjectType, array $fields, string $purpose): Collection
    {
        $decision = $this->access->decide($account, Permission::DataExport, $unit);
        if ($decision->denied()) {
            $this->access->recordDenial($subjectType, $unit->public_id, $unit->public_id, $decision->basis);

            throw (new AccessDenied($subjectType, $unit->public_id, $unit->public_id, Permission::DataExport->value))->alreadyRecorded();
        }
        $rows = $query->get();
        $this->reads->handle(RecordDataAccess::EXPORT, $subjectType, $unit->public_id, ['rows' => $rows->count(), 'fields' => $fields, 'decision' => $decision->basis], $unit->public_id, $purpose);

        return $rows;
    }

    /**
     * Runs a list query; a result larger than `organization.reads.bulk_threshold` is a bulk read and is audited.
     * Ordinary list views below the threshold leave no audit entry.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, TModel>
     */
    public function fetch(User $account, Builder $query, string $subjectType, string $purpose): Collection
    {
        $rows = $query->get();
        if ($rows->count() > (int) config('organization.reads.bulk_threshold')) {
            $this->reads->handle(RecordDataAccess::BULK_READ, $subjectType, 'list', ['rows' => $rows->count(), 'account_id' => $account->getKey()], null, $purpose);
        }

        return $rows;
    }

    /**
     * Current members visible to a technical process: only within its declared purpose (members.view, scope)
     * and, for a process ordered by an account, only where that account holds members.view today. Audited.
     *
     * @return Builder<Membership>
     */
    public function systemMemberships(): Builder
    {
        $purpose = $this->system->activePurpose() ?? throw new LogicException('A system read requires an active system purpose.');
        if (! $purpose->allows(Permission::MembersView)) {
            throw new LogicException('The system purpose does not include members.view.');
        }
        $now = CarbonImmutable::now('UTC');
        $units = $purpose->coveredOrganizationIds($now);
        if ($purpose->onBehalfOf !== null) {
            $units = array_intersect($units, $this->access->grantedOrganizationIds($purpose->onBehalfOf, Permission::MembersView));
        }
        $units = array_values($units);
        $this->reads->handle(RecordDataAccess::SYSTEM_READ, 'membership', 'list', [
            'purpose' => $purpose->describe(),
            'organizations' => Organization::query()->whereKey($units)->orderBy('id')->pluck('public_id')->all(),
        ], null, $purpose->purpose);

        return Membership::query()->whereIn('organization_id', $units)->effectiveAt($now);
    }

    /** @return Builder<RoleAssignment> */
    private function currentRoleAssignments(User $account): Builder
    {
        $catalog = $this->access->roleGrantCatalog($account);
        $roleIds = AccessRole::query()->whereIn('public_id', array_keys($catalog))->pluck('id', 'public_id');
        $auditedUnits = $this->access->grantedOrganizationIds($account, Permission::RolesAuditView);

        return RoleAssignment::query()->where(function (Builder $query) use ($account, $catalog, $roleIds, $auditedUnits): void {
            $query->where('user_id', $account->getKey())->orWhereIn('scope_organization_id', $auditedUnits);
            foreach ($catalog as $rolePublicId => $units) {
                $query->orWhere(fn (Builder $grantable) => $grantable->where('access_role_id', $roleIds[$rolePublicId])->whereIn('scope_organization_id', $units));
            }
        });
    }

    private function isPast(?DateTimeInterface $at): bool
    {
        return $at !== null && CarbonImmutable::instance($at)->lessThan(CarbonImmutable::now('UTC'));
    }

    /**
     * Units whose history of the given kind the account may view today; without any, the attempt is a denial
     * (audited, 403). Checked before anything of the past moment is computed.
     *
     * @return list<int>
     */
    private function historyUnits(User $account, Permission $permission, string $subjectType, ?DateTimeInterface $at = null): array
    {
        $units = $this->access->historyOrganizationIds($account, $permission, $at);
        if ($units === []) {
            $this->access->recordDenial($subjectType, 'history', null, [
                'ability' => $permission->value,
                'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'account_id' => $account->getKey(),
                'decision' => 'denied',
                'reason' => 'history_not_permitted',
            ]);

            throw (new AccessDenied($subjectType, 'history', null, $permission->value))->alreadyRecorded();
        }

        return $units;
    }

    /**
     * People represented now with profile.view. An ended representation gives nothing, also for a past moment.
     *
     * @return list<int>
     */
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
