<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\DataVisibility;
use App\Domain\Organization\Access\PlatformAccess;
use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\CreateOrganizationUnit;
use App\Domain\Organization\Actions\FoundOrganization;
use App\Domain\Organization\Actions\GrantPlatformRole;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Actions\RenameOrganization;
use App\Domain\Organization\Actions\RevokeRoleAssignment;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Organization\OrganizationHierarchy;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Exceptions\IdempotencyConflict;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\RunsAsSystem;
use Tests\TestCase;

/**
 * E3.10a (Z-043): founding an organization gives the founder rights in it only; units, moves, renames and
 * archiving go through the central decision; a founder catalog entry `*` covers roles defined later.
 */
class StructureControlTest extends TestCase
{
    use LazilyRefreshDatabase, RunsAsSystem;

    private function as(User $account, Closure $operation): mixed
    {
        return $this->app->make(ActorContext::class)->runAs(Actor::account((string) $account->id), $operation);
    }

    private function found(User $founder, string $name = 'Fundacja Zielona', ?string $requestKey = null): Organization
    {
        $organization = $this->as($founder, fn () => $this->app->make(FoundOrganization::class)->handle($name, 'Założenie organizacji', $requestKey ?? (string) Str::ulid()));
        $this->travel(1)->second();

        return $organization;
    }

    private function assertDenied(Closure $attempt, string $reason): AccessDenied
    {
        try {
            $attempt();
        } catch (AccessDenied $denied) {
            $this->assertSame($reason, AuditEntry::on('audit')->where('action', 'access.denied')->latest('id')->first()->after_values['reason']);

            return $denied;
        }
        $this->fail("Oczekiwano odmowy: {$reason}");
    }

    public function test_founder_gets_the_founder_role_in_that_organization_only(): void
    {
        $founder = User::factory()->withTwoFactor()->create();

        $organization = $this->found($founder);

        $assignment = RoleAssignment::query()->where('user_id', $founder->id)->sole();
        $this->assertSame($organization->id, $assignment->scope_organization_id);
        $this->assertSame(ScopeInheritance::UnitAndDescendants, $assignment->scope_inheritance);
        $role = $assignment->role;
        $this->assertSame('Administrator organizacji', $role->name);
        $this->assertTrue($role->requires_mfa);
        $this->assertEquals([['role' => '*', 'include_descendants' => true, 'max_days' => null, 'requires_approval' => false]], $role->grant_rules);
        $this->assertSame('assignment', $this->app->make(AccessDecider::class)->decide($founder, Permission::StructureManage, $organization)->reason);
        foreach (PlatformPermission::cases() as $permission) {
            $this->assertNotSame('assignment', $this->app->make(PlatformAccess::class)->decide($founder, $permission)->reason, 'Założyciel nie dostaje praw platformy.');
        }
        $founded = AuditEntry::query()->where('action', 'organization.founded')->sole();
        $this->assertSame([$founder->id, $role->public_id], [$founded->after_values['founder_account_id'], $founded->after_values['founder_role']]);
    }

    public function test_founder_rights_need_mfa_and_founding_needs_a_verified_account(): void
    {
        $withoutMfa = User::factory()->create();
        $organization = $this->found($withoutMfa);
        $this->assertSame('mfa_required', $this->app->make(AccessDecider::class)->decide($withoutMfa, Permission::StructureManage, $organization)->reason);

        $unverified = User::factory()->unverified()->create();
        $denied = $this->assertDenied(fn () => $this->found($unverified, 'Bez potwierdzenia'), 'email_unverified');
        $this->assertSame('access.email_unverified', $denied->getMessage());
        $this->assertDenied(fn () => $this->app->make(FoundOrganization::class)->handle('Proces', 'Import', (string) Str::ulid()), 'actor_without_account');
        $this->assertSame(1, Organization::query()->count());
    }

    public function test_units_are_created_and_moved_only_with_structure_manage_on_both_ends(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        $foreignFounder = User::factory()->withTwoFactor()->create();
        $foreign = $this->found($foreignFounder, 'Klub Obcy');

        $branch = $this->as($founder, fn () => $this->app->make(CreateOrganizationUnit::class)->handle($organization, 'Oddział Kraków', 'Nowy oddział'));
        $this->travel(1)->second();
        $team = $this->as($founder, fn () => $this->app->make(CreateOrganizationUnit::class)->handle($organization, 'Zespół', 'Nowy zespół'));
        $this->travel(1)->second();
        $this->assertSame($organization->id, OrganizationParent::query()->where('organization_id', $branch->id)->sole()->parent_id);
        $this->assertSame('assignment', $this->app->make(AccessDecider::class)->decide($founder, Permission::MembersManage, $branch)->reason, 'Prawa sięgają nowej jednostki przez dziedziczenie.');

        $this->as($founder, fn () => $this->app->make(MoveOrganization::class)->handle($team, $branch, 'Reorganizacja'));
        $this->assertDenied(fn () => $this->as($founder, fn () => $this->app->make(MoveOrganization::class)->handle($team, $foreign, 'Przekazanie')), 'no_matching_assignment');
        $this->assertDenied(fn () => $this->as($foreignFounder, fn () => $this->app->make(MoveOrganization::class)->handle($team, $foreign, 'Przejęcie')), 'no_matching_assignment');
        $this->assertDenied(fn () => $this->as($foreignFounder, fn () => $this->app->make(CreateOrganizationUnit::class)->handle($organization, 'Obca jednostka', 'Próba')), 'no_matching_assignment');
        $this->assertSame($branch->id, OrganizationParent::query()->where('organization_id', $team->id)->whereNull('valid_to')->sole()->parent_id);
    }

    public function test_rename_and_archive_need_organization_manage(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        $viewerRole = $this->asSystem(fn () => $this->app->make(CreateAccessRole::class)->handle($organization, 'Podgląd', ['organization.view'], 'Rola'));
        $viewer = User::factory()->create();
        $this->asSystem(fn () => $this->app->make(AssignRole::class)->handle($viewer, $viewerRole, $organization, ScopeInheritance::UnitOnly, null, 'Nadanie'));
        $this->travel(1)->second();

        $this->assertDenied(fn () => $this->as($viewer, fn () => $this->app->make(RenameOrganization::class)->handle($organization, 'Przejęta', 'Próba')), 'no_matching_assignment');
        $this->assertDenied(fn () => $this->as($viewer, fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, 'Próba')), 'no_matching_assignment');
        $this->assertSame('Fundacja Zielona', $organization->fresh()->name);

        $this->as($founder, fn () => $this->app->make(RenameOrganization::class)->handle($organization, 'Fundacja Niebieska', 'Zmiana statutu'));
        $this->as($founder, fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, 'Likwidacja', 'Fundacja Niebieska'));
        $this->assertSame(['Fundacja Niebieska', OrganizationStatus::Archived], [$organization->fresh()->name, $organization->fresh()->status]);
    }

    public function test_any_role_catalog_covers_roles_defined_later_in_the_organization_and_its_units(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        $branch = $this->as($founder, fn () => $this->app->make(CreateOrganizationUnit::class)->handle($organization, 'Oddział', 'Nowy oddział'));
        $this->travel(1)->second();
        [$trainer, $branchRole] = $this->as($founder, fn () => [
            $this->app->make(CreateAccessRole::class)->handle($organization, 'Trener', ['members.view'], 'Nowa rola'),
            $this->app->make(CreateAccessRole::class)->handle($branch, 'Kierownik oddziału', ['members.view', 'members.manage'], 'Nowa rola'),
        ]);
        $foreignRole = $this->asSystem(fn () => $this->app->make(CreateAccessRole::class)->handle(Organization::factory()->create(), 'Obca', ['members.view'], 'Rola'));
        $this->travel(1)->second();
        $employee = User::factory()->create();

        $this->as($founder, fn () => $this->app->make(AssignRole::class)->handle($employee, $trainer, $organization, ScopeInheritance::UnitOnly, null, 'Zatrudnienie'));
        $this->as($founder, fn () => $this->app->make(AssignRole::class)->handle($employee, $branchRole, $branch, ScopeInheritance::UnitOnly, null, 'Kierownik'));
        $catalog = $this->app->make(AccessDecider::class)->roleGrantCatalog($founder);
        $this->assertArrayHasKey($trainer->public_id, $catalog);
        $this->assertArrayHasKey($branchRole->public_id, $catalog);
        $this->assertArrayNotHasKey($foreignRole->public_id, $catalog);
        $this->assertSame('no_matching_assignment', $this->app->make(AccessDecider::class)->decideRoleGrant($founder, 'assign', $foreignRole, $organization, null, $employee->id)->reason);
    }

    public function test_a_manager_without_the_any_role_entry_cannot_hand_it_out(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        [$deputy, $hr] = $this->as($founder, function () use ($organization): array {
            $deputy = $this->app->make(CreateAccessRole::class)->handle($organization, 'Zastępca', ['roles.assign', 'members.view'], 'Rola', [['role' => '*', 'include_descendants' => true]]);

            return [$deputy, $this->app->make(CreateAccessRole::class)->handle($organization, 'Kadry', ['roles.assign', 'members.view'], 'Rola', [['role' => $deputy->public_id]])];
        });
        $hrManager = User::factory()->withTwoFactor()->create();
        $this->as($founder, fn () => $this->app->make(AssignRole::class)->handle($hrManager, $hr, $organization, ScopeInheritance::UnitOnly, null, 'Kadry'));
        $this->travel(1)->second();

        $this->assertDenied(fn () => $this->as($hrManager, fn () => $this->app->make(AssignRole::class)->handle(User::factory()->create(), $deputy, $organization, ScopeInheritance::UnitOnly, null, 'Zastępca')), 'delegation_power_exceeds_own');
        $this->assertSame(0, RoleAssignment::query()->where('access_role_id', $deputy->id)->count());
        $this->assertInstanceOf(AccessRole::class, $deputy);
    }

    /** A role in `$organization` given directly by the test (as an earlier administrator would have done). */
    private function holder(Organization $organization, array $permissions, Organization $scope, ScopeInheritance $inheritance = ScopeInheritance::UnitAndDescendants, bool $mfa = true, bool $requiresMfa = false): User
    {
        $role = $this->asSystem(fn () => $this->app->make(CreateAccessRole::class)->handle($organization, 'Rola '.Str::random(6), $permissions, 'Rola', requiresMfa: $requiresMfa));
        $account = $mfa ? User::factory()->withTwoFactor()->create() : User::factory()->create();
        $this->asSystem(fn () => $this->app->make(AssignRole::class)->handle($account, $role, $scope, $inheritance, null, 'Nadanie'));
        $this->travel(1)->second();

        return $account;
    }

    public function test_founder_catalog_never_contains_platform_roles_or_technical_permissions(): void
    {
        config(['platform.roles.support' => ['permissions' => ['platform.mfa.reset'], 'requires_mfa' => true]]);
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        $later = $this->as($founder, fn () => $this->app->make(CreateAccessRole::class)->handle($organization, 'Rola przyszła', ['members.view'], 'Nowa rola'));

        $catalog = $this->app->make(AccessDecider::class)->roleGrantCatalog($founder);
        $this->assertArrayHasKey($later->public_id, $catalog, 'Przyszła rola organizacji mieści się w katalogu.');
        foreach (['support', 'administrator', 'platform.install', 'platform.emergency.mfa_reset'] as $platform) {
            $this->assertArrayNotHasKey($platform, $catalog);
        }
        $this->assertDenied(fn () => $this->as($founder, fn () => $this->app->make(GrantPlatformRole::class)->handle(User::factory()->create(), 'support', 'Próba')), 'no_active_assignment');
        foreach (['platform.install', 'platform.administrators.manage', 'platform.emergency.mfa_reset'] as $technical) {
            try {
                $this->as($founder, fn () => $this->app->make(CreateAccessRole::class)->handle($organization, 'Techniczna '.$technical, [$technical], 'Próba'));
                $this->fail("Uprawnienie spoza organizacji przyjęte: {$technical}");
            } catch (ValidationException) {
            }
        }
    }

    public function test_founder_role_has_no_protected_data_or_export(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);

        foreach ([Permission::PeopleProtectedView, Permission::DataExport] as $permission) {
            $decision = $this->app->make(AccessDecider::class)->decide($founder, $permission, $organization);
            $this->assertSame('no_matching_assignment', $decision->reason);
            $this->assertSame('permission_missing', $decision->basis['considered'][0]['outcome']);
        }
    }

    public function test_repeated_submission_of_the_founding_form_founds_one_organization(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $key = (string) Str::ulid();

        $first = $this->found($founder, 'Fundacja Zielona', $key);
        $again = $this->found($founder, 'Fundacja Zielona', $key);

        $this->assertTrue($first->is($again));
        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'organization.founded')->count());
        $this->expectException(IdempotencyConflict::class);
        $this->found($founder, 'Inna nazwa', $key);
    }

    public function test_founding_attempts_are_limited_and_refusals_audited(): void
    {
        config(['organization.founding.attempts_per_hour' => 3]);
        $founder = User::factory()->withTwoFactor()->create();
        foreach (range(1, 3) as $index) {
            $this->found($founder, "Organizacja {$index}");
        }

        try {
            $this->found($founder, 'Czwarta');
            $this->fail('Oczekiwano limitu prób.');
        } catch (ThrottleRequestsException $limited) {
            $this->assertSame('organization.founding.too_many_attempts', $limited->getMessage());
        }
        $this->assertSame('founding_rate_limited', AuditEntry::on('audit')->where('action', 'access.denied')->latest('id')->first()->after_values['reason']);
        $this->assertSame(3, Organization::query()->count());
        $this->travel(61)->minutes();
        $this->found($founder, 'Po godzinie');
        $this->assertSame(4, Organization::query()->count());
    }

    public function test_a_limit_of_organizations_per_account_can_be_set_in_configuration(): void
    {
        config(['organization.founding.max_per_account' => 1]);
        $founder = User::factory()->withTwoFactor()->create();
        $this->found($founder);

        $denied = $this->assertDenied(fn () => $this->found($founder, 'Druga'), 'founding_limit_reached');
        $this->assertSame('access.founding_limit_reached', $denied->getMessage());
        $this->assertSame(1, Organization::query()->count());
    }

    public function test_whole_organization_and_unit_need_separate_rights(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        $unit = $this->as($founder, fn () => $this->app->make(CreateOrganizationUnit::class)->handle($organization, 'Sekcja', 'Nowa sekcja'));
        $this->travel(1)->second();
        $localAdmin = $this->holder($organization, ['structure.manage', 'organization.view'], $unit);
        $organizationManager = $this->holder($organization, ['organization.manage', 'organization.view'], $organization);

        $this->as($localAdmin, fn () => $this->app->make(RenameOrganization::class)->handle($unit, 'Sekcja Północ', 'Nowa nazwa'));
        $this->assertDenied(fn () => $this->as($localAdmin, fn () => $this->app->make(RenameOrganization::class)->handle($organization, 'Przejęta', 'Próba')), 'no_matching_assignment');
        $this->as($organizationManager, fn () => $this->app->make(RenameOrganization::class)->handle($organization, 'Fundacja Niebieska', 'Zmiana statutu'));
        $this->assertDenied(fn () => $this->as($organizationManager, fn () => $this->app->make(RenameOrganization::class)->handle($unit, 'Inna', 'Próba')), 'no_matching_assignment');
        $this->assertSame(['Fundacja Niebieska', 'Sekcja Północ'], [$organization->fresh()->name, $unit->fresh()->name]);
    }

    public function test_moving_a_unit_needs_rights_over_the_unit_the_old_and_the_new_parent(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        [$north, $south, $team] = array_map(fn (string $name) => tap($this->as($founder, fn () => $this->app->make(CreateOrganizationUnit::class)->handle($organization, $name, 'Struktura')), fn () => $this->travel(1)->second()), ['Północ', 'Południe', 'Zespół']);
        $this->as($founder, fn () => $this->app->make(MoveOrganization::class)->handle($team, $north, 'Zespół na północy'));
        $this->travel(1)->second();
        $northAdmin = $this->holder($organization, ['structure.manage'], $north);
        $southAdmin = $this->holder($organization, ['structure.manage'], $south);
        $both = $this->holder($organization, ['structure.manage'], $north);
        $this->asSystem(fn () => $this->app->make(AssignRole::class)->handle($both, RoleAssignment::query()->where('user_id', $both->id)->sole()->role, $south, ScopeInheritance::UnitAndDescendants, null, 'Drugie miejsce'));
        $this->travel(1)->second();

        $this->assertDenied(fn () => $this->as($northAdmin, fn () => $this->app->make(MoveOrganization::class)->handle($team, $south, 'Do miejsca bez praw')), 'no_matching_assignment');
        $this->assertDenied(fn () => $this->as($southAdmin, fn () => $this->app->make(MoveOrganization::class)->handle($team, $south, 'Z miejsca bez praw')), 'no_matching_assignment');
        $this->as($both, fn () => $this->app->make(MoveOrganization::class)->handle($team, $south, 'Przeniesienie'));
        $this->assertSame($south->id, OrganizationParent::query()->where('organization_id', $team->id)->whereNull('valid_to')->sole()->parent_id);
    }

    public function test_archiving_a_whole_organization_needs_confirmation_reason_and_mfa(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        $managerWithoutMfa = $this->holder($organization, ['organization.manage'], $organization, mfa: false);

        $denied = $this->assertDenied(fn () => $this->as($founder, fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, 'Likwidacja')), 'confirmation_missing');
        $this->assertSame('access.confirmation_missing', $denied->getMessage());
        $this->assertDenied(fn () => $this->as($founder, fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, 'Likwidacja', 'fundacja zielona')), 'confirmation_missing');
        $this->assertDenied(fn () => $this->as($managerWithoutMfa, fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, 'Likwidacja', 'Fundacja Zielona')), 'mfa_required');
        try {
            $this->as($founder, fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, '  ', 'Fundacja Zielona'));
            $this->fail('Oczekiwano wymogu powodu.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $this->assertSame(OrganizationStatus::Active, $organization->fresh()->status);

        $this->as($founder, fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, 'Likwidacja', 'Fundacja Zielona'));
        $this->assertSame(OrganizationStatus::Archived, $organization->fresh()->status);
    }

    public function test_archiving_a_unit_needs_rights_over_it_and_its_parent_and_keeps_history(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        $unit = $this->as($founder, fn () => $this->app->make(CreateOrganizationUnit::class)->handle($organization, 'Sekcja', 'Nowa sekcja'));
        $this->travel(1)->second();
        $localAdmin = $this->holder($organization, ['structure.manage', 'organization.manage'], $unit);
        $beforeArchive = now()->toImmutable();
        $this->travel(1)->second();

        $this->assertDenied(fn () => $this->as($localAdmin, fn () => $this->app->make(ArchiveOrganization::class)->handle($unit, 'Likwidacja sekcji')), 'no_matching_assignment');
        $this->as($founder, fn () => $this->app->make(ArchiveOrganization::class)->handle($unit, 'Likwidacja sekcji'));

        $this->assertSame(OrganizationStatus::Archived, $unit->fresh()->status);
        $this->assertSame($organization->id, OrganizationParent::query()->where('organization_id', $unit->id)->sole()->parent_id, 'Dawne położenie zostaje.');
        $this->assertSame([$organization->id], $this->app->make(OrganizationHierarchy::class)->ancestorsAt($unit, $beforeArchive)->modelKeys());
        $this->assertSame(1, RoleAssignment::query()->where('user_id', $localAdmin->id)->where('scope_organization_id', $unit->id)->count(), 'Przypisania zostają.');
        $this->assertSame(1, AuditEntry::query()->where(['action' => 'organization.created', 'subject_id' => (string) $unit->id])->count(), 'Audyt zostaje.');
    }

    public function test_archiving_a_unit_archives_its_whole_subtree_and_keeps_every_position(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        $create = fn (Organization $parent, string $name) => tap($this->as($founder, fn () => $this->app->make(CreateOrganizationUnit::class)->handle($parent, $name, 'Struktura')), fn () => $this->travel(1)->second());
        $circle = $create($organization, 'Koło Wołów');
        $team = $create($circle, 'WOK');
        $section = $create($team, 'Sekcja WOK');
        $other = $create($organization, 'Koło Jelenia Góra');

        $this->as($founder, fn () => $this->app->make(ArchiveOrganization::class)->handle($circle, 'Likwidacja koła'));

        foreach ([$circle, $team, $section] as $unit) {
            $this->assertSame(OrganizationStatus::Archived, $unit->fresh()->status, $unit->name);
            $this->assertTrue($unit->fresh()->archived_at->equalTo($circle->fresh()->archived_at), 'Ta sama chwila archiwizacji.');
            $this->assertSame(1, AuditEntry::query()->where(['action' => 'organization.updated', 'subject_id' => (string) $unit->id, 'reason' => 'Likwidacja koła'])->count(), 'Każda jednostka ma własny wpis w audycie.');
        }
        $this->assertSame(OrganizationStatus::Active, $other->fresh()->status);
        $this->assertSame([$team->id, $circle->id, $organization->id], $this->app->make(OrganizationHierarchy::class)->ancestorsAt($section, now()->subSecond())->modelKeys(), 'Położenie jednostek zostaje w historii.');
        $this->assertSame(3, OrganizationParent::query()->whereIn('organization_id', [$circle->id, $team->id, $section->id])->whereNull('valid_to')->count());
    }

    public function test_archived_units_are_visible_only_with_todays_history_right(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->found($founder);
        $circle = $this->as($founder, fn () => $this->app->make(CreateOrganizationUnit::class)->handle($organization, 'Koło Wołów', 'Struktura'));
        $this->travel(1)->second();
        $team = $this->as($founder, fn () => $this->app->make(CreateOrganizationUnit::class)->handle($circle, 'WOK', 'Struktura'));
        $this->travel(1)->second();
        $viewer = $this->holder($organization, ['organization.view'], $organization);
        $historian = $this->holder($organization, ['structure.history.view'], $organization);
        $this->as($founder, fn () => $this->app->make(ArchiveOrganization::class)->handle($circle, 'Likwidacja koła'));
        $this->travel(1)->second();
        $visibility = $this->app->make(DataVisibility::class);

        $this->assertEqualsCanonicalizing([$circle->id, $team->id], $visibility->archivedOrganizations($founder)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$circle->id, $team->id], $visibility->archivedOrganizations($historian)->pluck('id')->all());
        $this->assertSame($team->id, $visibility->findArchivedOrganization($historian, $team->public_id)->id);
        $this->assertDenied(fn () => $visibility->archivedOrganizations($viewer), 'history_not_permitted');

        $this->asSystem(fn () => $this->app->make(RevokeRoleAssignment::class)->handle(RoleAssignment::query()->where('user_id', $historian->id)->sole(), 'Koniec funkcji'));
        $this->travel(1)->second();
        $this->assertDenied(fn () => $visibility->archivedOrganizations($historian), 'history_not_permitted');

        $foreignFounder = User::factory()->withTwoFactor()->create();
        $this->found($foreignFounder, 'Klub Obcy');
        $this->assertDenied(fn () => $visibility->findArchivedOrganization($foreignFounder, $team->public_id), 'not_visible');
    }
}
