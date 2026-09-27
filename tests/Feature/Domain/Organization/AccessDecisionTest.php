<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\AccessDecision;
use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Actions\AdmitMember;
use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Actions\RetireAccessRole;
use App\Domain\Organization\Actions\RevokeRoleAssignment;
use App\Domain\Organization\Actions\UpdateAccessRole;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccessDecisionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $headquarters;

    private Organization $region;

    private Organization $branch;

    private Organization $foreign;

    private AccessRole $coordinator;

    private AccessRole $administrator;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
        [$this->headquarters, $this->region, $this->branch, $this->foreign] = Organization::factory()->count(4)->create()->all();
        $move = $this->app->make(MoveOrganization::class);
        $move->handle($this->region, $this->headquarters, 'Struktura');
        $move->handle($this->branch, $this->region, 'Struktura');
        $this->system(function (): void {
            $this->coordinator = $this->app->make(CreateAccessRole::class)->handle($this->headquarters, 'Koordynator', ['members.view', 'members.manage'], 'Rola');
            $this->administrator = $this->app->make(CreateAccessRole::class)->handle($this->headquarters, 'Administrator', ['members.view', 'members.manage', 'roles.assign', 'roles.manage'], 'Rola');
        });
        $this->account = User::factory()->create();
        $this->travel(1)->minute();
    }

    private function system(\Closure $operation): mixed
    {
        return $this->app->make(SystemAuthority::class)->run('test setup', $operation);
    }

    private function grant(User $account, AccessRole $role, Organization $scope, ScopeInheritance $inheritance = ScopeInheritance::UnitOnly, ?DateTimeInterface $until = null): RoleAssignment
    {
        return $this->system(fn () => $this->app->make(AssignRole::class)->handle($account, $role, $scope, $inheritance, $until, 'Nadanie'));
    }

    private function decide(Organization $target, Permission $permission = Permission::MembersManage, ?DateTimeInterface $at = null, ?User $account = null): AccessDecision
    {
        return $this->app->make(AccessDecider::class)->decide($account ?? $this->account, $permission, $target, $at);
    }

    private function asAccount(User $account, \Closure $operation): mixed
    {
        return $this->app->make(ActorContext::class)->runAs(Actor::account((string) $account->id), $operation);
    }

    public function test_unit_only_gives_access_to_that_unit_and_nothing_below(): void
    {
        $assignment = $this->grant($this->account, $this->coordinator, $this->region);

        $allowed = $this->decide($this->region);
        $this->assertTrue($allowed->allowed);
        $this->assertSame(AccessDecision::REASON_ASSIGNMENT, $allowed->reason);
        $this->assertSame([
            'assignment' => $assignment->public_id, 'role' => $this->coordinator->public_id, 'role_version' => 1,
            'scope_organization' => $this->region->public_id, 'scope_inheritance' => 'unit_only', 'structure_path' => [],
        ], array_intersect_key($allowed->basis, array_flip(['assignment', 'role', 'role_version', 'scope_organization', 'scope_inheritance', 'structure_path'])));

        $below = $this->decide($this->branch);
        $this->assertTrue($below->denied());
        $this->assertSame(AccessDecision::REASON_NO_MATCHING_ASSIGNMENT, $below->reason);
        $this->assertSame([['assignment' => $assignment->public_id, 'outcome' => 'scope_not_covering']], $below->basis['considered']);
    }

    public function test_inheritance_reaches_down_with_the_structure_path_but_never_up(): void
    {
        $this->grant($this->account, $this->coordinator, $this->region, ScopeInheritance::UnitAndDescendants);

        $down = $this->decide($this->branch);
        $link = OrganizationParent::query()->where('organization_id', $this->branch->id)->whereNull('valid_to')->sole();
        $this->assertTrue($down->allowed);
        $this->assertSame([$link->public_id], $down->basis['structure_path']);

        $this->assertTrue($this->decide($this->headquarters)->denied(), 'Brak dziedziczenia w górę.');
    }

    public function test_foreign_organization_is_always_denied(): void
    {
        $this->grant($this->account, $this->administrator, $this->headquarters, ScopeInheritance::UnitAndDescendants);

        $decision = $this->decide($this->foreign);

        $this->assertTrue($decision->denied());
        $this->assertSame($this->foreign->public_id, $decision->basis['target_organization']);
    }

    public function test_moving_a_unit_changes_access_now_but_the_past_decision_is_reconstructed(): void
    {
        $this->grant($this->account, $this->coordinator, $this->region, ScopeInheritance::UnitAndDescendants);
        $before = now()->toImmutable();
        $oldLink = OrganizationParent::query()->where('organization_id', $this->branch->id)->whereNull('valid_to')->sole();
        $this->travel(1)->minute();

        $this->app->make(MoveOrganization::class)->handle($this->branch, $this->headquarters, 'Reorganizacja');

        $this->assertTrue($this->decide($this->branch)->denied(), 'Obecnie jednostka jest poza zakresem.');
        $past = $this->decide($this->branch, at: $before);
        $this->assertTrue($past->allowed);
        $this->assertSame([$oldLink->public_id], $past->basis['structure_path'], 'Podstawa wskazuje ówczesny okres struktury.');
    }

    public function test_expired_revoked_and_not_yet_started_assignments_give_no_access(): void
    {
        $expiring = $this->grant($this->account, $this->coordinator, $this->region, until: now()->addDay());
        $revokedAccount = User::factory()->create();
        $revoked = $this->grant($revokedAccount, $this->coordinator, $this->region);
        $beforeAssignments = now()->subMinute();

        $this->assertTrue($this->decide($this->region)->allowed);
        $this->travel(2)->days();
        $this->assertSame(AccessDecision::REASON_NO_ACTIVE_ASSIGNMENT, $this->decide($this->region)->reason, 'Rola wygasła.');

        $this->system(fn () => $this->app->make(RevokeRoleAssignment::class)->handle($revoked, 'Odwołanie'));
        $this->travel(1)->second();
        $this->assertTrue($this->decide($this->region, account: $revokedAccount)->denied(), 'Rola odwołana.');
        $this->assertTrue($this->decide($this->region, at: $beforeAssignments, account: $revokedAccount)->denied(), 'Rola jeszcze nieobowiązująca (przyszła względem chwili).');
        $this->assertTrue($this->decide($this->region, at: $expiring->valid_from, account: $this->account)->allowed);
    }

    public function test_inactive_role_or_organization_gives_no_access(): void
    {
        $this->grant($this->account, $this->coordinator, $this->region, ScopeInheritance::UnitAndDescendants);
        $beforeRetirement = now()->toImmutable();
        $this->travel(1)->minute();

        $this->app->make(ArchiveOrganization::class)->handle($this->branch, 'Likwidacja');
        $this->assertSame(AccessDecision::REASON_TARGET_INACTIVE, $this->decide($this->branch)->reason, 'Jednostka zarchiwizowana.');

        $this->system(fn () => $this->app->make(RetireAccessRole::class)->handle($this->coordinator, 'Wycofanie'));
        $this->travel(1)->second();
        $retired = $this->decide($this->region);
        $this->assertSame('role_inactive', $retired->basis['considered'][0]['outcome'], 'Rola wycofana.');
        $this->assertTrue($this->decide($this->region, at: $beforeRetirement)->allowed, 'Przeszła decyzja bez zmian.');
    }

    public function test_past_decision_uses_the_role_version_valid_at_that_moment(): void
    {
        $this->grant($this->account, $this->coordinator, $this->region);
        $before = now()->toImmutable();
        $this->travel(1)->minute();

        $this->system(fn () => $this->app->make(UpdateAccessRole::class)->handle($this->coordinator, 'Koordynator', ['members.view'], 'Ograniczenie'));

        $this->assertTrue($this->decide($this->region)->denied());
        $past = $this->decide($this->region, at: $before);
        $this->assertTrue($past->allowed);
        $this->assertSame(1, $past->basis['role_version']);
    }

    public function test_relation_roles_do_not_grant_access(): void
    {
        $person = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Anna', 'family_name' => 'Nowak']);
        $this->app->make(AuditReason::class)->because('link', fn () => $this->account->forceFill(['person_id' => $person->id])->save());
        $this->app->make(AdmitMember::class)->handle($person, $this->region, 'chair', 'Przyjęcie');

        $this->assertSame(AccessDecision::REASON_NO_ACTIVE_ASSIGNMENT, $this->decide($this->region, Permission::MembersView)->reason);
    }

    public function test_unauthorized_actor_cannot_assign_or_revoke_roles_and_the_denial_is_recorded_with_its_basis(): void
    {
        $intruder = User::factory()->create();
        $this->grant($intruder, $this->coordinator, $this->headquarters, ScopeInheritance::UnitAndDescendants);
        $target = User::factory()->create();
        $existing = $this->grant($target, $this->coordinator, $this->region);

        foreach ([
            fn () => $this->app->make(AssignRole::class)->handle($target, $this->coordinator, $this->branch, ScopeInheritance::UnitOnly, null, 'Samowola'),
            fn () => $this->app->make(RevokeRoleAssignment::class)->handle($existing, 'Samowola'),
        ] as $attempt) {
            try {
                $this->asAccount($intruder, $attempt);
                $this->fail('Actor without roles.assign must be refused.');
            } catch (AccessDenied $denied) {
                $this->assertTrue($denied->recorded);
            }
        }

        $this->assertSame(0, RoleAssignment::query()->where('scope_organization_id', $this->branch->id)->count());
        $this->assertNull($existing->fresh()->valid_to);
        $denial = AuditEntry::on('audit')->where('action', 'access.denied')->where('subject_type', 'role_assignment')->orderBy('id')->first();
        $this->assertSame(['roles.assign', 'no_matching_assignment'], [$denial->after_values['permission'], $denial->after_values['reason']]);
        $this->assertSame('permission_missing', $denial->after_values['considered'][0]['outcome']);
    }

    public function test_process_without_system_authority_is_denied(): void
    {
        $this->expectException(AccessDenied::class);
        $this->app->make(AssignRole::class)->handle($this->account, $this->coordinator, $this->region, ScopeInheritance::UnitOnly, null, 'Bez uprawnień');
    }

    public function test_authorized_administrator_assigns_within_scope_and_the_grant_is_recorded(): void
    {
        $admin = User::factory()->create();
        $this->grant($admin, $this->administrator, $this->headquarters, ScopeInheritance::UnitAndDescendants);

        $assignment = $this->asAccount($admin, fn () => $this->app->make(AssignRole::class)->handle($this->account, $this->coordinator, $this->branch, ScopeInheritance::UnitOnly, null, 'Nadanie przez administratora'));

        $this->assertTrue($this->decide($this->branch)->allowed);
        $grant = AuditEntry::query()->where('action', 'access.granted')->where('actor_id', (string) $admin->id)->sole();
        $this->assertSame(['roles.assign', $this->branch->public_id], [$grant->after_values['permission'], $grant->after_values['target_organization']]);
        $this->assertSame('role_assignment.created', AuditEntry::query()->where('subject_id', (string) $assignment->id)->value('action'));
    }

    public function test_no_self_assignment_and_no_delegation_of_permissions_one_does_not_hold(): void
    {
        $admin = User::factory()->create();
        $this->grant($admin, $this->administrator, $this->headquarters, ScopeInheritance::UnitAndDescendants);
        $superRole = $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->headquarters, 'Audytor', ['audit.view'], 'Rola'));

        foreach ([
            fn () => $this->app->make(AssignRole::class)->handle($admin, $this->coordinator, $this->branch, ScopeInheritance::UnitOnly, null, 'Sobie'),
            fn () => $this->app->make(AssignRole::class)->handle($this->account, $superRole, $this->region, ScopeInheritance::UnitOnly, null, 'Cudze uprawnienie'),
            fn () => $this->app->make(CreateAccessRole::class)->handle($this->headquarters, 'Szersza', ['audit.view'], 'Eskalacja'),
        ] as $attempt) {
            try {
                $this->asAccount($admin, $attempt);
                $this->fail('Self-assignment and escalation must be refused.');
            } catch (AccessDenied) {
            }
        }
        $reasons = AuditEntry::on('audit')->where('action', 'access.denied')->get()->map(fn ($e) => $e->after_values['reason'])->all();
        $this->assertSame(['self_assignment', 'delegation_exceeds_own_permissions', 'delegation_exceeds_own_permissions'], $reasons);
    }

    public function test_laravel_gate_asks_the_same_decider(): void
    {
        $this->grant($this->account, $this->coordinator, $this->region);

        $this->assertTrue(Gate::forUser($this->account)->allows('members.manage', $this->region));
        $this->assertFalse(Gate::forUser($this->account)->allows('members.manage', $this->branch));
        $this->assertFalse(Gate::forUser($this->account)->allows('roles.assign', $this->region));
    }

    public function test_http_denial_is_audited_once_with_the_decision_basis(): void
    {
        Route::middleware('web')->post('/_probe/assign', function () {
            app(AssignRole::class)->handle(User::query()->findOrFail(request('account')), AccessRole::query()->firstOrFail(), Organization::query()->findOrFail(request('scope')), ScopeInheritance::UnitOnly, null, 'HTTP');
        });
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post('/_probe/assign', ['account' => $this->account->id, 'scope' => $this->region->id])->assertForbidden();

        $denial = AuditEntry::on('audit')->where('action', 'access.denied')->sole();
        $this->assertSame(AccessDecision::REASON_NO_ACTIVE_ASSIGNMENT, $denial->after_values['reason']);
    }
}
