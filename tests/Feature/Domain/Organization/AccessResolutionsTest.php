<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Identity\Actions\ActOnBehalf;
use App\Domain\Identity\Actions\EndRepresentation;
use App\Domain\Identity\Actions\GrantRepresentation;
use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\DataVisibility;
use App\Domain\Organization\Access\RoleCandidate;
use App\Domain\Organization\Access\RoleCandidateSearch;
use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Access\SystemPurpose;
use App\Domain\Organization\Actions\AdmitMember;
use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\EndMembership;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Actions\RevokeRoleAssignment;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\Fixtures\AnyScopeTestPurpose;
use Tests\Support\RunsAsSystem;
use Tests\TestCase;

/** E3.7b: consequences of the Z-034a resolutions (Jakub, 2026-10-05). */
class AccessResolutionsTest extends TestCase
{
    use LazilyRefreshDatabase, RunsAsSystem;

    private Organization $company;

    private Organization $office;

    private Organization $foreign;

    private AccessRole $accountant;

    private AccessRole $administrator;

    private AccessRole $historian;

    private AccessRole $hrManager;

    private User $hr;

    private Person $anna;

    private Membership $annaInOffice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
        [$this->company, $this->office, $this->foreign] = Organization::factory()->count(3)->create()->all();
        $this->asSystem(fn () => $this->app->make(MoveOrganization::class)->handle($this->office, $this->company, 'Struktura'));
        $this->system(function (): void {
            $create = $this->app->make(CreateAccessRole::class);
            $this->accountant = $create->handle($this->company, 'Księgowy', ['audit.view'], 'Rola');
            $this->administrator = $create->handle($this->company, 'Administrator', ['organization.view', 'members.view'], 'Rola');
            $this->historian = $create->handle($this->company, 'Archiwista', ['organization.view', 'members.view', 'members.history.view', 'structure.history.view'], 'Rola');
            $this->hrManager = $create->handle($this->company, 'Kadry', ['roles.assign'], 'Rola', [
                ['role' => $this->accountant->public_id, 'include_descendants' => true, 'max_days' => 365],
            ]);
        });
        $this->hr = User::factory()->withTwoFactor()->create();
        $this->grant($this->hr, $this->hrManager, $this->company);
        [$this->anna, $this->annaInOffice] = $this->member('Anna', $this->office, 'anna.nowak@example.pl');
        $this->member('Bartek', $this->foreign, 'bartek@example.pl');
        $this->travel(1)->minute();
    }

    private function system(Closure $operation): mixed
    {
        return $this->app->make(SystemAuthority::class)->run(new AnyScopeTestPurpose, $operation);
    }

    private function grant(User $account, AccessRole $role, Organization $scope, ScopeInheritance $inheritance = ScopeInheritance::UnitAndDescendants): RoleAssignment
    {
        return $this->system(fn () => $this->app->make(AssignRole::class)->handle($account, $role, $scope, $inheritance, $role->is($this->accountant) ? now()->addDays(30) : null, 'Nadanie'));
    }

    private function role(string $name, array $permissions): AccessRole
    {
        return $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->company, $name, $permissions, 'Rola'));
    }

    /** @return array{0: Person, 1: Membership} */
    private function member(string $given, Organization $unit, string $email): array
    {
        $person = $this->app->make(RegisterPerson::class)->handle(['given_name' => $given, 'family_name' => 'Nowak']);
        User::factory()->withTwoFactor()->create(['person_id' => $person->id, 'email' => $email]);

        return [$person, $this->app->make(AdmitMember::class)->handle($person, $unit, 'member', 'Przyjęcie')];
    }

    private function visibility(): DataVisibility
    {
        return $this->app->make(DataVisibility::class);
    }

    private function candidates(string $query, ?Organization $scope = null, ?AccessRole $role = null): array
    {
        return $this->app->make(RoleCandidateSearch::class)->search($this->hr, $role ?? $this->accountant, $scope ?? $this->office, $query);
    }

    private function denied(Closure $operation): AccessDenied
    {
        try {
            $operation();
        } catch (AccessDenied $denied) {
            return $denied;
        }
        $this->fail('Oczekiwano odmowy dostępu.');
    }

    private function auditCount(?string $action = null): int
    {
        $count = 0;
        foreach (['mysql', 'audit'] as $connection) {
            $count += AuditEntry::on($connection)->when($action, fn ($query) => $query->where('action', $action))->count();
        }

        return $count;
    }

    public function test_candidate_search_returns_minimal_masked_data(): void
    {
        $results = $this->candidates('Ann');

        $this->assertCount(1, $results);
        $this->assertInstanceOf(RoleCandidate::class, $results[0]);
        $this->assertSame(['personId', 'fullName', 'email', 'emailMasked', 'accountActive', 'inRequestedScope'], array_keys(get_object_vars($results[0])));
        $this->assertSame($this->anna->public_id, $results[0]->personId);
        $this->assertSame('Anna Nowak', $results[0]->fullName);
        $this->assertSame('a•••@e•••.pl', $results[0]->email);
        $this->assertTrue($results[0]->emailMasked);
        $this->assertTrue($results[0]->accountActive);
        $this->assertTrue($results[0]->inRequestedScope);

        $this->grant($this->hr, $this->role('Kontakty', ['people.contacts.view']), $this->company);
        $this->assertSame('anna.nowak@example.pl', $this->candidates('Ann')[0]->email, 'Pełny e-mail tylko z osobnym uprawnieniem.');
    }

    public function test_candidate_search_stays_inside_the_grantable_scope(): void
    {
        $warehouse = Organization::factory()->create();
        $this->asSystem(fn () => $this->app->make(MoveOrganization::class)->handle($warehouse, $this->company, 'Struktura'));
        $this->member('Celina', $warehouse, 'celina@example.pl');
        $this->travel(1)->minute();

        $this->assertSame([], $this->candidates('Bartek'), 'Członek obcej organizacji nie jest wyszukiwany.');
        $flags = collect($this->candidates('Nowak'))->mapWithKeys(fn (RoleCandidate $candidate) => [$candidate->fullName => $candidate->inRequestedScope])->all();
        $this->assertSame(['Anna Nowak' => true, 'Celina Nowak' => false], $flags, 'Osoba z innej jednostki zakresu jest oznaczona jako spoza wskazanej jednostki.');

        $this->denied(fn () => $this->candidates('Bar', $this->foreign));
        $this->denied(fn () => $this->candidates('Ann', $this->office, $this->administrator));
        $this->assertSame(2, AuditEntry::on('audit')->where(['action' => 'access.denied', 'subject_type' => 'role_candidate'])->count());

        $this->expectException(ValidationException::class);
        $this->candidates('A');
    }

    public function test_candidate_search_does_not_reveal_whether_an_email_has_an_account(): void
    {
        $existingOutside = $this->candidates('bartek@example.pl');
        $missing = $this->candidates('nikt@example.pl');

        $this->assertSame([], $existingOutside);
        $this->assertSame($missing, $existingOutside, 'Ta sama odpowiedź dla konta spoza zakresu i nieistniejącego.');
        $this->assertSame('Anna Nowak', $this->candidates('ANNA.NOWAK@example.pl')[0]->fullName, 'Dokładny e-mail w zakresie działa.');
        $this->assertSame([], $this->candidates('anna'.'%'), 'Znaki wieloznaczne nie poszerzają wyszukiwania.');
    }

    public function test_candidate_search_never_reaches_the_whole_account_base(): void
    {
        $loner = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Anastazja', 'family_name' => 'Nowak']);
        User::factory()->withTwoFactor()->create(['person_id' => $loner->id, 'email' => 'anastazja@example.pl']);
        $this->app->make(EndMembership::class)->handle($this->annaInOffice, 'Rezygnacja');
        $this->travel(1)->minute();

        $this->assertSame([], $this->candidates('Ana'), 'Konto bez członkostwa w zakresie nie jest wyszukiwane po imieniu.');
        $this->assertSame([], $this->candidates('Nowak'), 'Były członek ani konto spoza struktur nie trafiają do wyników.');
        $this->assertSame([], $this->candidates('anastazja@example.pl'), 'Ani po dokładnym e-mailu.');
        $this->assertSame([], $this->candidates('anna.nowak@example.pl'));
        $this->assertSame([], $this->candidates('___'), 'Znaki wieloznaczne nie wyszukują wszystkich.');
        $this->assertSame([], $this->candidates('@example.pl'), 'Fragment e-maila nie wyszukuje.');
    }

    public function test_candidate_searches_are_limited(): void
    {
        config(['organization.candidate_search.max_per_minute' => 3]);
        $this->candidates('Ann');
        $this->candidates('nikt@example.pl');
        $this->denied(fn () => $this->candidates('Bar', $this->foreign));

        $otherManager = User::factory()->withTwoFactor()->create();
        $this->grant($otherManager, $this->hrManager, $this->company);
        $this->assertCount(1, $this->app->make(RoleCandidateSearch::class)->search($otherManager, $this->accountant, $this->office, 'Ann'), 'Limit liczony osobno dla każdego konta.');

        try {
            $this->candidates('Ann');
            $this->fail('Oczekiwano przekroczenia limitu wyszukiwań.');
        } catch (ThrottleRequestsException) {
        }
        $this->travel(61)->seconds();
        $this->assertCount(1, $this->candidates('Ann'), 'Po upływie minuty wyszukiwanie znów działa.');
    }

    public function test_roles_outside_the_catalog_stay_hidden_without_roles_audit(): void
    {
        $adminInOffice = $this->grant(User::factory()->withTwoFactor()->create(), $this->administrator, $this->office);

        $this->assertNotContains($adminInOffice->id, $this->visibility()->roleAssignments($this->hr)->pluck('id')->all());

        $this->grant($this->hr, $this->role('Kontrola ról', ['roles.audit.view']), $this->company);
        $this->assertContains($adminInOffice->id, $this->visibility()->roleAssignments($this->hr)->pluck('id')->all(), 'Osobne uprawnienie kontroli ról.');
    }

    public function test_former_members_need_the_history_permission(): void
    {
        $admin = User::factory()->withTwoFactor()->create();
        $this->grant($admin, $this->administrator, $this->company);
        $historian = User::factory()->withTwoFactor()->create();
        $this->grant($historian, $this->historian, $this->company);
        $this->app->make(EndMembership::class)->handle($this->annaInOffice, 'Rezygnacja');
        $this->travel(1)->minute();

        $this->assertSame([], $this->visibility()->memberships($admin)->pluck('id')->all(), 'members.view pokazuje tylko obecnych członków.');
        $this->assertNotContains($this->anna->id, $this->visibility()->people($admin)->pluck('id')->all());
        $this->denied(fn () => $this->visibility()->membershipHistory($admin));

        $this->assertSame([$this->annaInOffice->id], $this->visibility()->membershipHistory($historian)->pluck('id')->all());
        $this->assertContains($this->anna->id, $this->visibility()->people($historian)->pluck('id')->all());

        config(['organization.history.visible_days' => 30]);
        $this->travel(31)->days();
        $this->assertSame([], $this->visibility()->membershipHistory($historian)->pluck('id')->all(), 'Okres wynikający z polityki przechowywania.');
        $this->assertSame(1, Membership::query()->whereKey($this->annaInOffice->id)->count(), 'Dane nie są usuwane automatycznie.');
    }

    public function test_representation_gives_only_the_data_the_action_needs(): void
    {
        $child = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Zosia', 'family_name' => 'Nowak', 'birth_date' => '2015-03-01']);
        $this->app->make(AdmitMember::class)->handle($child, $this->office, 'member', 'Przyjęcie');
        $parent = User::factory()->withTwoFactor()->create(['person_id' => $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Maria', 'family_name' => 'Nowak'])->id]);
        $representation = $this->app->make(GrantRepresentation::class)->handle($parent->person, $child, [RepresentationScope::ProfileView], RepresentationMethod::Document, 'akt urodzenia', now()->subSecond(), 'Opiekun');
        $acting = $this->app->make(ActOnBehalf::class);

        $data = $acting->visibleData($parent, $child, RepresentationScope::ProfileView);
        $this->assertSame(['public_id', 'given_name', 'family_name', 'birth_date'], array_keys($data), 'Tylko pola potrzebne do czynności.');
        $this->assertSame(['Zosia', '2015-03-01'], [$data['given_name'], $data['birth_date']->format('Y-m-d')]);
        $this->assertSame(404, $this->denied(fn () => $acting->visibleData($parent, $child, RepresentationScope::ContactsView))->status(), 'Inna czynność — brak danych.');
        $this->assertSame([], $this->visibility()->memberships($parent)->pluck('id')->all(), 'Bez dostępu do danych organizacji.');
        $this->denied(fn () => $this->visibility()->findPerson($parent, $this->anna->public_id));

        $acting->handle($parent, $child, RepresentationScope::ProfileView, fn () => null);
        $this->app->make(EndRepresentation::class)->handle($representation, now(), 'Pełnoletność');
        $this->travel(1)->minute();

        $this->denied(fn () => $acting->visibleData($parent, $child, RepresentationScope::ProfileView));
        $this->assertNotContains($child->id, $this->visibility()->people($parent)->pluck('id')->all(), 'Koniec reprezentacji odbiera bieżący dostęp.');
        $this->assertSame(1, AuditEntry::query()->where('action', 'person.acted_on_behalf')->count(), 'Historia wcześniejszych działań zostaje.');
    }

    public function test_history_of_an_archived_unit_stays_available_with_the_history_permission(): void
    {
        $historian = User::factory()->withTwoFactor()->create();
        $this->grant($historian, $this->historian, $this->company);
        $admin = User::factory()->withTwoFactor()->create();
        $this->grant($admin, $this->administrator, $this->company);
        $beforeArchive = now()->toImmutable();
        $this->travel(1)->minute();
        $officeAudit = AuditEntry::query()->where('organization_id', $this->office->public_id)->count();

        $this->asSystem(fn () => $this->app->make(ArchiveOrganization::class)->handle($this->office, 'Likwidacja'));
        $this->travel(1)->minute();

        $this->assertSame([], $this->visibility()->memberships($admin)->pluck('id')->all(), 'Bez prawa do historii jednostka znika.');
        $this->assertContains($this->annaInOffice->id, $this->visibility()->membershipHistory($historian)->pluck('id')->all());
        $this->assertContains($this->office->id, $this->visibility()->organizations($historian, at: $beforeArchive)->pluck('id')->all());
        $this->assertGreaterThan(0, $officeAudit);
        $this->assertGreaterThanOrEqual($officeAudit, AuditEntry::query()->where('organization_id', $this->office->public_id)->count(), 'Archiwizacja nie usuwa audytu.');
    }

    public function test_moving_a_unit_does_not_rewrite_where_it_was(): void
    {
        $historian = User::factory()->withTwoFactor()->create();
        $this->grant($historian, $this->historian, $this->company);
        $beforeMove = now()->toImmutable();
        $this->travel(1)->minute();

        $this->asSystem(fn () => $this->app->make(MoveOrganization::class)->handle($this->office, $this->foreign, 'Przekazanie'));
        $this->travel(1)->minute();

        $this->assertNotContains($this->office->id, $this->visibility()->organizations($historian)->pluck('id')->all());
        $this->assertContains($this->office->id, $this->visibility()->organizations($historian, at: $beforeMove)->pluck('id')->all());
        $this->assertSame([$this->annaInOffice->id], $this->visibility()->memberships($historian, $beforeMove)->pluck('id')->all());
    }

    public function test_protected_reads_exports_and_denials_are_audited(): void
    {
        $officer = User::factory()->withTwoFactor()->create();
        $this->grant($officer, $this->role('Dane chronione', ['people.protected.view', 'members.view', 'data.export']), $this->company);

        $this->assertSame(['given_name' => 'Anna'], $this->visibility()->readProtected($officer, $this->anna, ['given_name'], 'weryfikacja'));
        $this->assertSame(['fields' => ['given_name']], AuditEntry::on('audit')->where(['action' => 'data.read', 'subject_id' => $this->anna->public_id])->sole()->after_values);

        $rows = $this->visibility()->export($officer, $this->office, $this->visibility()->memberships($officer)->where('organization_id', $this->office->id), 'membership', ['person_id', 'function'], 'lista obecności');
        $export = AuditEntry::on('audit')->where('action', 'data.exported')->sole();
        $this->assertSame(1, $rows->count());
        $this->assertSame(1, $export->after_values['rows']);
        $this->assertSame('lista obecności', $export->reason);

        $this->denied(fn () => $this->visibility()->export($this->hr, $this->office, Membership::query(), 'membership', ['person_id'], 'próba'));
        $this->assertSame(1, AuditEntry::on('audit')->where(['action' => 'access.denied', 'subject_type' => 'membership'])->count());
        $this->denied(fn () => $this->visibility()->readProtected($this->hr, $this->anna, ['given_name'], 'próba'));
        $this->assertSame(1, AuditEntry::on('audit')->where(['action' => 'access.denied', 'subject_type' => 'person'])->count());
    }

    public function test_ordinary_allowed_list_is_not_audited_but_a_bulk_read_is(): void
    {
        $admin = User::factory()->withTwoFactor()->create();
        $this->grant($admin, $this->administrator, $this->company);
        $before = $this->auditCount();

        $this->visibility()->memberships($admin)->get();
        $this->visibility()->people($admin)->get();
        $this->visibility()->fetch($admin, $this->visibility()->memberships($admin), 'membership', 'lista członków');
        $this->assertSame($before, $this->auditCount(), 'Zwykła lista nie tworzy wpisów audytu.');

        config(['organization.reads.bulk_threshold' => 0]);
        $this->visibility()->fetch($admin, $this->visibility()->memberships($admin), 'membership', 'lista członków');
        $this->assertSame(1, $this->auditCount('data.bulk_read'));
    }

    public function test_an_earlier_date_does_not_bring_back_lost_access(): void
    {
        $former = User::factory()->withTwoFactor()->create();
        $assignment = $this->grant($former, $this->historian, $this->company);
        $whenAllowed = now()->addSecond()->toImmutable();
        $this->travel(1)->minute();
        $this->assertSame([$this->annaInOffice->id], $this->visibility()->memberships($former, $whenAllowed)->pluck('id')->all());

        $this->system(fn () => $this->app->make(RevokeRoleAssignment::class)->handle($assignment, 'Koniec zatrudnienia'));
        $this->travel(1)->minute();

        $denied = $this->denied(fn () => $this->visibility()->memberships($former, $whenAllowed));
        $this->assertSame(403, $denied->status() ?? 403);
        $this->denied(fn () => $this->visibility()->organizations($former, at: $whenAllowed));
        $this->assertSame('history_not_permitted', AuditEntry::on('audit')->where(['action' => 'access.denied', 'subject_id' => 'history'])->latest('id')->first()->after_values['reason']);
        $this->assertTrue($this->app->make(AccessDecider::class)->decide($former, Permission::MembersView, $this->office, $whenAllowed)->allowed, 'Techniczne odtworzenie decyzji pozostaje możliwe — to nie jest prawo przeglądania.');

        $viewer = User::factory()->withTwoFactor()->create();
        $this->grant($viewer, $this->administrator, $this->company);
        $this->denied(fn () => $this->visibility()->memberships($viewer, $whenAllowed));
    }

    public function test_history_checks_todays_right_first_and_then_uses_the_past_structure(): void
    {
        $formerHistorian = User::factory()->withTwoFactor()->create();
        $formerAssignment = $this->grant($formerHistorian, $this->historian, $this->company);
        $beforeMove = now()->addSecond()->toImmutable();
        $this->travel(1)->minute();
        $this->asSystem(fn () => $this->app->make(MoveOrganization::class)->handle($this->office, $this->foreign, 'Przekazanie'));
        $this->system(fn () => $this->app->make(RevokeRoleAssignment::class)->handle($formerAssignment, 'Koniec zatrudnienia'));
        $this->travel(1)->minute();

        $newHistorian = User::factory()->withTwoFactor()->create();
        $this->grant($newHistorian, $this->historian, $this->company);
        $foreignHistorian = User::factory()->withTwoFactor()->create();
        $this->grant($foreignHistorian, $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->foreign, 'Archiwista', ['organization.view', 'members.view', 'members.history.view', 'structure.history.view'], 'Rola')), $this->foreign);
        $this->travel(1)->minute();

        $this->denied(fn () => $this->visibility()->memberships($formerHistorian, $beforeMove));
        $this->denied(fn () => $this->visibility()->organizations($formerHistorian, at: $beforeMove));
        $this->assertSame(2, AuditEntry::on('audit')->where(['action' => 'access.denied', 'subject_id' => 'history', 'after_values->account_id' => $formerHistorian->id])->count(), 'Rola z przeszłości bez dzisiejszego prawa = odmowa.');

        $this->assertSame([$this->annaInOffice->id], $this->visibility()->memberships($newHistorian, $beforeMove)->pluck('id')->all(), 'Dzisiejsze prawo + struktura z tamtej chwili, bez wymogu roli w przeszłości.');
        $this->assertContains($this->office->id, $this->visibility()->organizations($newHistorian, at: $beforeMove)->pluck('id')->all());
        $this->assertSame([], $this->visibility()->memberships($newHistorian)->pluck('id')->all(), 'Dziś jednostka jest poza zakresem.');

        $this->assertNotContains($this->annaInOffice->id, $this->visibility()->memberships($foreignHistorian, $beforeMove)->pluck('id')->all(), 'W tamtej chwili jednostka nie należała do zakresu.');
        $this->assertNotContains($this->office->id, $this->visibility()->organizations($foreignHistorian, at: $beforeMove)->pluck('id')->all());
        $this->assertContains($this->annaInOffice->id, $this->visibility()->memberships($foreignHistorian)->pluck('id')->all());
    }

    public function test_pending_request_gives_nothing_and_shows_the_approver_only_what_is_needed(): void
    {
        $viewer = $this->role('Podgląd', ['organization.view', 'members.view']);
        $approverRole = $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->company, 'Zatwierdzający', ['roles.assign'], 'Rola', [
            ['role' => $viewer->public_id, 'include_descendants' => true, 'requires_approval' => true],
        ]));
        $requester = User::factory()->withTwoFactor()->create(['given_name' => 'Ewa', 'family_name' => 'Kadrowa']);
        $approver = User::factory()->withTwoFactor()->create();
        $this->grant($requester, $approverRole, $this->company);
        $this->grant($approver, $approverRole, $this->company);
        $this->travel(1)->minute();
        $grantee = User::query()->where('person_id', $this->anna->id)->sole();

        $request = $this->app->make(ActorContext::class)->runAs(Actor::account((string) $requester->id),
            fn () => $this->app->make(AssignRole::class)->handle($grantee, $viewer, $this->office, ScopeInheritance::UnitOnly, null, 'Zastępstwo w sekretariacie'));
        $this->travel(1)->minute();

        $this->assertTrue($this->app->make(AccessDecider::class)->decide($grantee, Permission::OrganizationView, $this->office)->denied());
        $this->assertSame([], $this->visibility()->organizations($grantee)->pluck('id')->all());

        $summaries = $this->visibility()->pendingRoleRequests($approver);
        $this->assertCount(1, $summaries);
        $summary = $summaries[0];
        $this->assertSame($request->public_id, $summary->requestId);
        $this->assertSame('Anna Nowak', $summary->granteeName);
        $this->assertSame('a•••@e•••.pl', $summary->granteeEmail);
        $this->assertSame(['Podgląd', $this->office->name, 'unit_only', 'Ewa Kadrowa', 'Zastępstwo w sekretariacie'], [$summary->roleName, $summary->scopeName, $summary->scopeInheritance, $summary->requestedBy, $summary->reason]);
        $this->assertNotNull($summary->requestedAt);
        $this->assertSame([], $this->visibility()->pendingRoleRequests($requester), 'Wnioskujący nie zatwierdza własnego wniosku.');
        $this->denied(fn () => $this->visibility()->findPerson($approver, $this->anna->public_id));
    }

    public function test_system_authority_is_limited_to_its_declared_purpose(): void
    {
        $authority = $this->app->make(SystemAuthority::class);
        $purpose = new SystemPurpose('Import ról biura', 'umowa wdrożeniowa', [Permission::RolesManage, Permission::MembersView], [$this->office]);

        $role = $authority->run($purpose, fn () => $this->app->make(CreateAccessRole::class)->handle($this->office, 'Biuro', ['members.view'], 'Import'));
        $this->assertSame($this->office->id, $role->organization_id);
        $this->assertSame(1, AuditEntry::on('audit')->where(['action' => 'system_authority.entered', 'subject_id' => 'Import ról biura'])->count());

        $this->denied(fn () => $authority->run($purpose, fn () => $this->app->make(CreateAccessRole::class)->handle($this->company, 'Firma', ['members.view'], 'Poza zakresem')));
        $this->denied(fn () => $authority->run($purpose, fn () => $this->app->make(AssignRole::class)->handle(User::factory()->withTwoFactor()->create(), $role, $this->office, ScopeInheritance::UnitOnly, null, 'Niezadeklarowane uprawnienie')));

        $members = $authority->run($purpose, fn () => $this->visibility()->systemMemberships()->pluck('id')->all());
        $this->assertSame([$this->annaInOffice->id], $members);
        $this->assertSame(1, AuditEntry::on('audit')->where('action', 'data.read_by_system')->count(), 'Odczyt procesu technicznego jest audytowany.');

        $this->expectException(InvalidArgumentException::class);
        new SystemPurpose('Wszystko', 'brak', Permission::cases(), []);
    }

    public function test_process_ordered_by_an_account_never_exceeds_its_current_permissions(): void
    {
        $authority = $this->app->make(SystemAuthority::class);
        $ordered = new SystemPurpose('Raport cykliczny', 'zlecenie kadr', [Permission::RolesAssign], [$this->company], onBehalfOf: $this->hr);
        $employee = User::factory()->withTwoFactor()->create();

        $authority->run($ordered, fn () => $this->app->make(AssignRole::class)->handle($employee, $this->accountant, $this->office, ScopeInheritance::UnitOnly, now()->addDays(10), 'Zlecone'));
        $this->assertSame(1, RoleAssignment::query()->where('user_id', $employee->id)->count());

        $this->system(fn () => $this->app->make(RevokeRoleAssignment::class)->handle(RoleAssignment::query()->where('user_id', $this->hr->id)->sole(), 'Zmiana stanowiska'));
        $this->travel(1)->minute();

        $this->denied(fn () => $authority->run($ordered, fn () => $this->app->make(AssignRole::class)->handle(User::factory()->withTwoFactor()->create(), $this->accountant, $this->office, ScopeInheritance::UnitOnly, now()->addDays(10), 'Zlecone')));
        $this->assertSame('ordering_account_not_permitted', AuditEntry::on('audit')->where('action', 'access.denied')->latest('id')->first()->after_values['reason']);
    }

    public function test_an_account_cannot_use_system_authority(): void
    {
        $this->expectException(LogicException::class);
        $this->app->make(ActorContext::class)->runAs(Actor::account((string) $this->hr->id),
            fn () => $this->app->make(SystemAuthority::class)->enter(new SystemPurpose('Próba', 'brak', [Permission::MembersView], [$this->office])));
    }
}
