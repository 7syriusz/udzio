<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Actions\ApproveRoleAssignment;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Actions\RevokeRoleAssignment;
use App\Domain\Organization\Actions\UpdateAccessRole;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\RelationStatus;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\AnyScopeTestPurpose;
use Tests\TestCase;

/**
 * E3.6a: roles are granted according to the role-granting catalog of the granting account's managing role,
 * not according to the operational permissions it holds.
 */
class RoleGrantCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $company;

    private Organization $office;

    private Organization $foreign;

    private AccessRole $accountant;

    private AccessRole $auditor;

    private AccessRole $hrManager;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
        [$this->company, $this->office, $this->foreign] = Organization::factory()->count(3)->create()->all();
        $this->app->make(MoveOrganization::class)->handle($this->office, $this->company, 'Struktura');
        $this->system(function (): void {
            $create = $this->app->make(CreateAccessRole::class);
            $this->accountant = $create->handle($this->company, 'Księgowy', ['members.view', 'audit.view'], 'Rola');
            $this->auditor = $create->handle($this->company, 'Audytor', ['audit.view'], 'Rola');
            $this->hrManager = $create->handle($this->company, 'Kadry', ['members.view', 'roles.assign'], 'Rola', [
                ['role' => $this->accountant->public_id, 'include_descendants' => true, 'max_days' => 365],
            ]);
        });
        $this->admin = User::factory()->withTwoFactor()->create();
        $this->employee = User::factory()->withTwoFactor()->create();
        $this->grant($this->admin, $this->hrManager, $this->company, ScopeInheritance::UnitAndDescendants);
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

    private function as(User $account, Closure $operation): mixed
    {
        return $this->app->make(ActorContext::class)->runAs(Actor::account((string) $account->id), $operation);
    }

    private function assignAs(User $actor, User $grantee, AccessRole $role, Organization $scope, ?DateTimeInterface $until = null): RoleAssignment
    {
        return $this->as($actor, fn () => $this->app->make(AssignRole::class)->handle($grantee, $role, $scope, ScopeInheritance::UnitOnly, $until ?? now()->addDays(30), 'Nadanie roli'));
    }

    /** @return list<string> */
    private function deniedReasons(): array
    {
        return AuditEntry::on('audit')->where('action', 'access.denied')->orderBy('id')->get()->map(fn ($e) => $e->after_values['reason'])->all();
    }

    private function assertDenied(Closure $attempt, string $reason): void
    {
        try {
            $attempt();
            $this->fail("Expected denial: {$reason}");
        } catch (AccessDenied) {
        }
        $this->assertSame($reason, last($this->deniedReasons()));
    }

    public function test_1_administrator_grants_a_role_with_operational_permissions_they_do_not_hold(): void
    {
        $this->assertTrue($this->app->make(AccessDecider::class)->decide($this->admin, Permission::AuditView, $this->office)->denied(), 'Administrator kadr nie ma dostępu do audytu.');

        $assignment = $this->assignAs($this->admin, $this->employee, $this->accountant, $this->office);

        $this->assertSame(RelationStatus::Active, $assignment->status);
        $this->assertTrue($this->app->make(AccessDecider::class)->decide($this->employee, Permission::AuditView, $this->office)->allowed);
        $grant = AuditEntry::query()->where('action', 'access.granted')->where('actor_id', (string) $this->admin->id)->sole();
        $this->assertSame(['assign', $this->accountant->public_id], [$grant->after_values['operation'], $grant->after_values['granted_role']]);
        $this->assertSame($this->accountant->public_id, $grant->after_values['grant_rule']['role']);
    }

    public function test_2_role_outside_the_catalog_is_refused(): void
    {
        $this->assertDenied(fn () => $this->assignAs($this->admin, $this->employee, $this->auditor, $this->office), 'no_matching_assignment');
        $denial = AuditEntry::on('audit')->where('action', 'access.denied')->sole();
        $this->assertSame('role_not_in_catalog', $denial->after_values['considered'][0]['outcome']);
    }

    public function test_3_scope_outside_the_managed_units_is_refused(): void
    {
        $officeAdmin = User::factory()->withTwoFactor()->create();
        $this->grant($officeAdmin, $this->hrManager, $this->office);

        $this->assertDenied(fn () => $this->assignAs($officeAdmin, $this->employee, $this->accountant, $this->company), 'no_matching_assignment');
        $this->assertSame('scope_not_covering', AuditEntry::on('audit')->where('action', 'access.denied')->sole()->after_values['considered'][0]['outcome']);
        $this->assertDenied(fn () => $this->assignAs($this->admin, $this->employee, $this->accountant, $this->foreign), 'no_matching_assignment');
    }

    public function test_4_self_assignment_is_refused(): void
    {
        $this->assertDenied(fn () => $this->assignAs($this->admin, $this->admin, $this->accountant, $this->office), 'self_assignment');
        $this->assertSame(0, RoleAssignment::query()->where('user_id', $this->admin->id)->where('access_role_id', $this->accountant->id)->count());
    }

    public function test_5_indirect_self_escalation_is_refused(): void
    {
        $powerful = $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->company, 'Zastępca', ['roles.assign', 'roles.manage'], 'Rola', [
            ['role' => $this->auditor->public_id, 'include_descendants' => true],
        ]));
        $this->system(fn () => $this->app->make(UpdateAccessRole::class)->handle($this->hrManager, 'Kadry', ['members.view', 'roles.assign'], 'Katalog', [
            ['role' => $this->accountant->public_id, 'include_descendants' => true, 'max_days' => 365],
            ['role' => $powerful->public_id, 'include_descendants' => true],
        ]));
        $this->travel(1)->minute();

        // (a) handing an accomplice more role-granting power than one has
        $this->assertDenied(fn () => $this->assignAs($this->admin, $this->employee, $powerful, $this->office), 'delegation_power_exceeds_own');
        $exceeding = AuditEntry::on('audit')->where('action', 'access.denied')->sole()->after_values['exceeding'];
        $this->assertEqualsCanonicalizing(['roles.manage', 'catalog:'.$this->auditor->public_id], $exceeding);

        // (b) changing the definition of a role one holds
        $roleEditor = User::factory()->withTwoFactor()->create();
        $editorRole = $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->company, 'Redaktor ról', ['roles.manage', 'members.view'], 'Rola'));
        $this->grant($roleEditor, $editorRole, $this->company);
        $this->travel(1)->minute();
        $this->assertDenied(fn () => $this->as($roleEditor, fn () => $this->app->make(UpdateAccessRole::class)->handle($editorRole, 'Redaktor ról', ['roles.manage', 'members.view', 'audit.view'], 'Poszerzenie')), 'modifies_own_role');
        $this->assertSame(['members.view', 'roles.manage'], $editorRole->fresh()->permissions);
    }

    public function test_6_and_7_user_sees_a_generic_403_while_the_audit_keeps_the_full_reason(): void
    {
        Route::middleware('web')->post('/_probe/grant', function () {
            app(AssignRole::class)->handle(User::query()->findOrFail(request('grantee')), AccessRole::query()->findOrFail(request('role')), Organization::query()->findOrFail(request('scope')), ScopeInheritance::UnitOnly, now()->addDay(), 'HTTP');
        });

        config(['app.debug' => false]); // as in production (.env.production.example)
        $response = $this->actingAs($this->admin)->postJson('/_probe/grant', ['grantee' => $this->employee->id, 'role' => $this->auditor->id, 'scope' => $this->office->id]);

        $response->assertForbidden()->assertExactJson(['message' => 'Nie masz uprawnień do wykonania tej czynności.']);
        foreach ([$this->auditor->public_id, $this->hrManager->public_id, 'Audytor', 'Kadry', $this->company->public_id, 'role_not_in_catalog'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $denial = AuditEntry::on('audit')->where('action', 'access.denied')->sole();
        $this->assertSame('no_matching_assignment', $denial->after_values['reason']);
        $this->assertSame('role_not_in_catalog', $denial->after_values['considered'][0]['outcome']);
    }

    public function test_8_one_protected_operation_leaves_one_final_decision_linked_by_correlation(): void
    {
        foreach (range(1, 3) as $check) {
            Gate::forUser($this->admin)->allows('roles.assign', $this->office);
        }
        $this->assertSame(0, AuditEntry::query()->whereIn('action', ['access.granted'])->where('actor_id', (string) $this->admin->id)->count(), 'Sprawdzenia Gate nie zapisują decyzji.');

        $assignment = $this->assignAs($this->admin, $this->employee, $this->accountant, $this->office);

        $decisions = AuditEntry::query()->where('action', 'access.granted')->where('actor_id', (string) $this->admin->id)->get();
        $this->assertCount(1, $decisions);
        $change = AuditEntry::query()->where('action', 'role_assignment.created')->where('subject_id', (string) $assignment->id)->sole();
        $this->assertSame($change->correlation_id, $decisions->sole()->correlation_id);
    }

    public function test_repeated_checks_inside_one_operation_record_one_decision(): void
    {
        $this->as($this->admin, fn () => app(OperationCorrelation::class)->within(function (): void {
            $access = app(AccessDecider::class);
            foreach (range(1, 3) as $check) {
                $access->authorize(Permission::MembersView, $this->office);
            }
        }));

        $this->assertSame(1, AuditEntry::query()->where('action', 'access.granted')->where('actor_id', (string) $this->admin->id)->count());
    }

    public function test_catalog_limits_the_duration(): void
    {
        $this->assertDenied(fn () => $this->assignAs($this->admin, $this->employee, $this->accountant, $this->office, now()->addDays(400)), 'no_matching_assignment');
        $this->assertSame('duration_exceeds_limit', AuditEntry::on('audit')->where('action', 'access.denied')->sole()->after_values['considered'][0]['outcome']);

        $this->assertDenied(fn () => $this->as($this->admin, fn () => $this->app->make(AssignRole::class)->handle($this->employee, $this->accountant, $this->office, ScopeInheritance::UnitOnly, null, 'Bezterminowo')), 'no_matching_assignment');
    }

    public function test_approval_rule_keeps_the_assignment_pending_until_another_manager_approves(): void
    {
        $this->system(fn () => $this->app->make(UpdateAccessRole::class)->handle($this->hrManager, 'Kadry', ['members.view', 'roles.assign'], 'Zatwierdzanie', [
            ['role' => $this->accountant->public_id, 'include_descendants' => true, 'max_days' => 365, 'requires_approval' => true],
        ]));
        $secondAdmin = User::factory()->withTwoFactor()->create();
        $this->grant($secondAdmin, $this->hrManager, $this->company, ScopeInheritance::UnitAndDescendants);
        $this->travel(1)->minute();

        $pending = $this->assignAs($this->admin, $this->employee, $this->accountant, $this->office);
        $this->assertSame(RelationStatus::Pending, $pending->status);
        $this->assertTrue($this->app->make(AccessDecider::class)->decide($this->employee, Permission::AuditView, $this->office)->denied(), 'Oczekująca rola nic nie daje.');

        $this->assertDenied(fn () => $this->as($this->admin, fn () => $this->app->make(ApproveRoleAssignment::class)->handle($pending, 'Sam zatwierdzam')), 'approval_by_requester');
        $this->assertDenied(fn () => $this->as($this->employee, fn () => $this->app->make(ApproveRoleAssignment::class)->handle($pending, 'Zatwierdzam sobie')), 'self_assignment');

        $this->travel(1)->minute();
        $active = $this->as($secondAdmin, fn () => $this->app->make(ApproveRoleAssignment::class)->handle($pending, 'Zatwierdzenie'));

        $this->assertSame(RelationStatus::Active, $active->status);
        $this->assertTrue($active->valid_to->equalTo($pending->requested_until));
        $this->assertTrue($this->app->make(AccessDecider::class)->decide($this->employee, Permission::AuditView, $this->office)->allowed);
    }

    public function test_revocation_follows_the_same_catalog(): void
    {
        $assignment = $this->assignAs($this->admin, $this->employee, $this->accountant, $this->office);
        $auditorAssignment = $this->grant($this->employee, $this->auditor, $this->office);
        $this->travel(1)->minute();

        $this->assertDenied(fn () => $this->as($this->admin, fn () => $this->app->make(RevokeRoleAssignment::class)->handle($auditorAssignment, 'Spoza katalogu')), 'no_matching_assignment');
        $this->as($this->admin, fn () => $this->app->make(RevokeRoleAssignment::class)->handle($assignment, 'Odwołanie'));

        $this->assertNotNull($assignment->fresh()->valid_to);
        $this->assertNull($auditorAssignment->fresh()->valid_to);
    }
}
