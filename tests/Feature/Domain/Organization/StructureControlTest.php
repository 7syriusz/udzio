<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\PlatformAccess;
use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\CreateOrganizationUnit;
use App\Domain\Organization\Actions\FoundOrganization;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Actions\RenameOrganization;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

    private function found(User $founder, string $name = 'Fundacja Zielona'): Organization
    {
        $organization = $this->as($founder, fn () => $this->app->make(FoundOrganization::class)->handle($name, 'Założenie organizacji'));
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
        $this->assertDenied(fn () => $this->app->make(FoundOrganization::class)->handle('Proces', 'Import'), 'actor_without_account');
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
        $this->as($founder, fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, 'Likwidacja'));
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
}
