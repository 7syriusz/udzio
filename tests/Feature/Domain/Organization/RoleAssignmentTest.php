<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Actions\RetireAccessRole;
use App\Domain\Organization\Actions\RevokeRoleAssignment;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Exceptions\ValidityConflict;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class RoleAssignmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $headquarters;

    private Organization $region;

    private Organization $branch;

    private Organization $otherOrganization;

    private AccessRole $role;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
        [$this->headquarters, $this->region, $this->branch, $this->otherOrganization] = Organization::factory()->count(4)->create()->all();
        $move = $this->app->make(MoveOrganization::class);
        $move->handle($this->region, $this->headquarters, 'Struktura');
        $move->handle($this->branch, $this->region, 'Struktura');
        $this->role = $this->app->make(CreateAccessRole::class)->handle($this->headquarters, 'Koordynator', ['members.view', 'members.manage'], 'Rola');
        $this->account = User::factory()->create();
        $this->travel(1)->minute();
    }

    private function assign(Organization $scope, ScopeInheritance $inheritance = ScopeInheritance::UnitOnly, ?DateTimeInterface $until = null, ?AccessRole $role = null): RoleAssignment
    {
        return $this->app->make(AssignRole::class)->handle($this->account, $role ?? $this->role, $scope, $inheritance, $until, 'Powołanie na koordynatora');
    }

    public function test_assignment_is_audited_and_keeps_the_explicit_inheritance_policy(): void
    {
        $assignment = $this->assign($this->region);

        $this->assertSame([ScopeInheritance::UnitOnly, null], [$assignment->scope_inheritance, $assignment->valid_to]);
        $entry = AuditEntry::query()->where('action', 'role_assignment.created')->sole();
        $this->assertSame([$this->region->public_id, 'Powołanie na koordynatora'], [$entry->organization_id, $entry->reason]);
    }

    public function test_unit_only_scope_covers_just_that_unit(): void
    {
        $assignment = $this->assign($this->region);

        $this->assertTrue($assignment->covers($this->region, now()));
        $this->assertFalse($assignment->covers($this->branch, now()), 'Brak automatycznego dziedziczenia w dół.');
        $this->assertFalse($assignment->covers($this->headquarters, now()), 'Brak dziedziczenia w górę.');
    }

    public function test_unit_and_descendants_follows_the_structure_valid_at_the_moment(): void
    {
        $assignment = $this->assign($this->region, ScopeInheritance::UnitAndDescendants);
        $before = now()->toImmutable();

        $this->assertSame([$this->region->id, $this->branch->id], $assignment->coveredOrganizationIds($before));
        $this->assertFalse($assignment->covers($this->headquarters, $before));
        $this->assertFalse($assignment->covers($this->otherOrganization, $before), 'Obca organizacja nigdy nie jest w zakresie.');

        $this->travel(1)->minute();
        $this->app->make(MoveOrganization::class)->handle($this->branch, $this->headquarters, 'Reorganizacja');

        $this->assertFalse($assignment->covers($this->branch, now()), 'Po przeniesieniu jednostka wypada z zakresu.');
        $this->assertTrue($assignment->covers($this->branch, $before), 'Historia zakresu zostaje.');
    }

    public function test_scope_must_be_the_role_owner_or_a_unit_below_it(): void
    {
        $this->assertTrue($this->assign($this->branch)->covers($this->branch, now()));

        $regionalRole = $this->app->make(CreateAccessRole::class)->handle($this->region, 'Regionalna', ['members.view'], 'Rola regionu');
        foreach ([[$this->headquarters, $regionalRole], [$this->otherOrganization, $this->role]] as [$scope, $role]) {
            try {
                $this->assign($scope, role: $role);
                $this->fail('Scope outside the role owner must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('scope', $e->errors());
            }
        }
    }

    public function test_assignment_can_expire_and_is_not_active_afterwards(): void
    {
        $assignment = $this->assign($this->region, until: CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'));

        $active = fn (string $at) => RoleAssignment::query()->whereKey($assignment->id)->activeAt(CarbonImmutable::parse($at, 'UTC'))->exists();
        $this->assertTrue($active('2026-02-28 23:59:59'));
        $this->assertFalse($active('2026-03-01 00:00:00'));

        try {
            $this->assign($this->region);
            $this->fail('Overlapping assignment before expiry must be refused.');
        } catch (ValidityConflict) {
        }
        $this->expectException(ValidationException::class);
        $this->assign($this->branch, until: CarbonImmutable::parse('2025-12-31', 'UTC'));
    }

    public function test_revocation_ends_open_and_scheduled_assignments_now(): void
    {
        $open = $this->assign($this->region);
        $scheduled = $this->assign($this->branch, until: CarbonImmutable::parse('2026-12-31', 'UTC'));
        $this->travel(1)->day();
        $revoke = $this->app->make(RevokeRoleAssignment::class);

        $revoke->handle($open, 'Odwołanie');
        $revoke->handle($scheduled, 'Odwołanie przed terminem');

        foreach ([$open, $scheduled] as $assignment) {
            $this->assertTrue($assignment->fresh()->valid_to->equalTo(now()));
            $this->assertFalse(RoleAssignment::query()->whereKey($assignment->id)->activeAt(now())->exists());
        }
        $this->assertSame(2, AuditEntry::query()->where('action', 'role_assignment.updated')->where('reason', 'like', 'Odwołanie%')->count());
        $this->assertSame($open->id, $revoke->handle($open, 'Ponownie')->id, 'Ponowne odwołanie nic nie zmienia.');
    }

    public function test_retired_role_or_archived_scope_cannot_be_assigned(): void
    {
        $this->app->make(ArchiveOrganization::class)->handle($this->branch, 'Likwidacja');
        $retired = $this->app->make(RetireAccessRole::class)->handle(
            $this->app->make(CreateAccessRole::class)->handle($this->headquarters, 'Stara', ['members.view'], 'Rola'),
            'Wycofana',
        );

        foreach ([[$this->branch, $this->role, 'scope'], [$this->region, $retired, 'role']] as [$scope, $role, $field]) {
            try {
                $this->assign($scope, role: $role);
                $this->fail('Assignment must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    public function test_history_cannot_be_rewritten_or_deleted(): void
    {
        $assignment = $this->assign($this->region);

        try {
            $this->app->make(AuditReason::class)->because('Poszerzenie', fn () => $assignment->update(['scope_inheritance' => ScopeInheritance::UnitAndDescendants]));
            $this->fail('Assignment cannot be rewritten.');
        } catch (LogicException) {
        }

        $this->expectException(QueryException::class);
        DB::table('role_assignments')->where('id', $assignment->id)->delete();
    }
}
