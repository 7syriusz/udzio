<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\DataVisibility;
use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Actions\AdmitMember;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Actions\UpdateAccessRole;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\AnyScopeTestPurpose;
use Tests\TestCase;

/** E3.7a: data isolation revised after the E3.6 review — role data follows the role-granting catalog. */
class RoleDataVisibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $company;

    private Organization $office;

    private Organization $foreign;

    private AccessRole $accountant;

    private AccessRole $administrator;

    private AccessRole $hrManager;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
        [$this->company, $this->office, $this->foreign] = Organization::factory()->count(3)->create()->all();
        $this->app->make(MoveOrganization::class)->handle($this->office, $this->company, 'Struktura');
        $this->system(function (): void {
            $create = $this->app->make(CreateAccessRole::class);
            $this->accountant = $create->handle($this->company, 'Księgowy', ['audit.view'], 'Rola');
            $this->administrator = $create->handle($this->company, 'Administrator', ['organization.view', 'members.view', 'roles.manage'], 'Rola');
            $this->hrManager = $create->handle($this->company, 'Kadry', ['roles.assign'], 'Rola', [
                ['role' => $this->accountant->public_id, 'include_descendants' => true, 'max_days' => 365],
            ]);
        });
        $this->hr = User::factory()->withTwoFactor()->create();
        $this->grant($this->hr, $this->hrManager, $this->company, ScopeInheritance::UnitAndDescendants);
        $this->travel(1)->minute();
    }

    private function system(Closure $operation): mixed
    {
        return $this->app->make(SystemAuthority::class)->run(new AnyScopeTestPurpose, $operation);
    }

    private function grant(User $account, AccessRole $role, Organization $scope, ScopeInheritance $inheritance = ScopeInheritance::UnitOnly): RoleAssignment
    {
        return $this->system(fn () => $this->app->make(AssignRole::class)->handle($account, $role, $scope, $inheritance, null, 'Nadanie'));
    }

    private function visibility(): DataVisibility
    {
        return $this->app->make(DataVisibility::class);
    }

    public function test_manager_sees_assignments_only_of_catalog_roles_in_managed_units(): void
    {
        $accountantInOffice = $this->grant(User::factory()->withTwoFactor()->create(), $this->accountant, $this->office);
        $adminInOffice = $this->grant(User::factory()->withTwoFactor()->create(), $this->administrator, $this->office);
        $foreignRole = $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->foreign, 'Księgowy', ['audit.view'], 'Rola'));
        $foreignAssignment = $this->grant(User::factory()->withTwoFactor()->create(), $foreignRole, $this->foreign);

        $visible = $this->visibility()->roleAssignments($this->hr)->pluck('id')->all();

        $this->assertContains($accountantInOffice->id, $visible);
        $this->assertNotContains($adminInOffice->id, $visible, 'Przypisania ról spoza katalogu są ukryte.');
        $this->assertNotContains($foreignAssignment->id, $visible, 'Obca organizacja jest ukryta.');
        $this->assertContains(RoleAssignment::query()->where('user_id', $this->hr->id)->value('id'), $visible, 'Własne przypisania są widoczne.');
    }

    public function test_role_granting_power_does_not_give_operational_visibility_and_vice_versa(): void
    {
        $person = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Anna', 'family_name' => 'Nowak']);
        $this->app->make(AdmitMember::class)->handle($person, $this->office, 'member', 'Przyjęcie');
        $admin = User::factory()->withTwoFactor()->create();
        $this->grant($admin, $this->administrator, $this->company, ScopeInheritance::UnitAndDescendants);
        $this->travel(1)->minute();

        $this->assertSame([], $this->visibility()->memberships($this->hr)->pluck('id')->all(), 'Kadry bez members.view nie widzą członkostw.');
        $this->assertSame([], $this->visibility()->organizations($this->hr)->pluck('id')->all());
        $this->assertSame([], $this->visibility()->roleAssignments($admin)->where('user_id', '!=', $admin->id)->pluck('id')->all(), 'members.view i roles.manage nie dają wglądu w cudze przypisania.');
    }

    public function test_role_definitions_visible_to_role_managers_catalog_holders_and_holders(): void
    {
        $admin = User::factory()->withTwoFactor()->create();
        $this->grant($admin, $this->administrator, $this->company);
        $plain = User::factory()->withTwoFactor()->create();
        $this->grant($plain, $this->accountant, $this->office);
        $this->travel(1)->minute();

        $this->assertEqualsCanonicalizing([$this->accountant->id, $this->administrator->id, $this->hrManager->id], $this->visibility()->accessRoles($admin)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->accountant->id, $this->hrManager->id], $this->visibility()->accessRoles($this->hr)->pluck('id')->all());
        $this->assertSame([$this->accountant->id], $this->visibility()->accessRoles($plain)->pluck('id')->all());
    }

    public function test_pending_assignment_is_visible_to_the_manager_but_gives_the_grantee_nothing(): void
    {
        $viewer = $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->company, 'Podgląd', ['organization.view'], 'Rola'));
        $this->system(fn () => $this->app->make(UpdateAccessRole::class)->handle($this->hrManager, 'Kadry', ['roles.assign'], 'Zatwierdzanie', [
            ['role' => $this->accountant->public_id, 'include_descendants' => true, 'max_days' => 365],
            ['role' => $viewer->public_id, 'include_descendants' => true, 'requires_approval' => true],
        ]));
        $this->travel(1)->minute();
        $grantee = User::factory()->withTwoFactor()->create();

        $requested = $this->app->make(ActorContext::class)->runAs(Actor::account((string) $this->hr->id),
            fn () => $this->app->make(AssignRole::class)->handle($grantee, $viewer, $this->office, ScopeInheritance::UnitOnly, null, 'Wniosek kadr'));

        $this->assertSame('pending', $requested->status->value);
        $this->assertContains($requested->id, $this->visibility()->roleAssignments($this->hr)->pluck('id')->all(), 'Zatwierdzający widzi wniosek.');
        $this->assertSame([], $this->visibility()->organizations($grantee)->pluck('id')->all(), 'Oczekująca rola nie daje widoczności.');
    }

    public function test_visible_catalog_agrees_with_single_role_grant_decisions(): void
    {
        $decider = $this->app->make(AccessDecider::class);
        $catalog = $decider->roleGrantCatalog($this->hr);

        foreach (AccessRole::query()->get() as $role) {
            foreach (Organization::query()->get() as $unit) {
                $this->assertSame(
                    in_array($unit->id, $catalog[$role->public_id] ?? [], true),
                    $decider->decideRoleGrant($this->hr, 'revoke', $role, $unit)->allowed,
                    "Katalog i decyzja muszą być zgodne: {$role->name} w {$unit->public_id}",
                );
            }
        }
    }

    public function test_visibility_can_be_reconstructed_for_a_past_moment(): void
    {
        $admin = User::factory()->withTwoFactor()->create();
        $this->grant($admin, $this->administrator, $this->company, ScopeInheritance::UnitAndDescendants);
        // Since E3.7b a past moment needs today's right to that history.
        $history = $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->company, 'Historia', ['structure.history.view'], 'Rola'));
        $this->grant($admin, $history, $this->company, ScopeInheritance::UnitAndDescendants);
        $before = now()->addSecond()->toImmutable();
        $this->travel(1)->minute();

        $this->app->make(MoveOrganization::class)->handle($this->office, null, 'Wydzielenie');

        $this->assertSame([$this->company->id], $this->visibility()->organizations($admin)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->company->id, $this->office->id], $this->visibility()->organizations($admin, at: $before)->pluck('id')->all());
    }

    public function test_refused_reads_are_one_decision_per_operation_and_leak_nothing(): void
    {
        $hidden = $this->grant(User::factory()->withTwoFactor()->create(), $this->administrator, $this->office);

        $this->app->make(OperationCorrelation::class)->within(function () use ($hidden): void {
            foreach (range(1, 3) as $attempt) {
                try {
                    $this->visibility()->findRoleAssignment($this->hr, $hidden->public_id);
                } catch (AccessDenied) {
                }
            }
        });
        $this->assertSame(1, AuditEntry::on('audit')->where(['action' => 'access.denied', 'subject_id' => $hidden->public_id])->count());

        Route::middleware('web')->get('/_probe/assignments/{id}', fn (string $id) => app(DataVisibility::class)->findRoleAssignment(request()->user(), $id)->public_id);
        config(['app.debug' => false]);
        $response = $this->actingAs($this->hr)->getJson('/_probe/assignments/'.$hidden->public_id);
        $response->assertNotFound();
        foreach (['Administrator', $this->administrator->public_id, $this->office->public_id, 'not_visible'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }
}
