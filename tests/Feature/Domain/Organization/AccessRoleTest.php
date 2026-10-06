<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\RetireAccessRole;
use App\Domain\Organization\Actions\UpdateAccessRole;
use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Fixtures\AnyScopeTestPurpose;
use Tests\Support\RunsAsSystem;
use Tests\TestCase;

class AccessRoleTest extends TestCase
{
    use LazilyRefreshDatabase, RunsAsSystem;

    protected function setUp(): void
    {
        parent::setUp();
        // Role actions are authorized centrally (E3.6); these tests exercise the actions themselves.
        $this->app->make(SystemAuthority::class)->enter(new AnyScopeTestPurpose('test of role actions'));
    }

    private function create(Organization $organization, string $name = 'Sekretariat', array $permissions = ['members.view', 'members.manage']): AccessRole
    {
        return $this->app->make(CreateAccessRole::class)->handle($organization, $name, $permissions, 'Nowa rola');
    }

    public function test_permission_catalog_has_unique_operation_names_and_labels(): void
    {
        $values = array_map(fn (Permission $p) => $p->value, Permission::cases());

        $this->assertSame(count($values), count(array_unique($values)));
        foreach (Permission::cases() as $permission) {
            $this->assertMatchesRegularExpression('/^[a-z_]+(\.[a-z_]+)+$/', $permission->value);
            $this->assertNotSame('permissions.'.$permission->value, $permission->label(), 'Każde uprawnienie ma polską nazwę (E3.6b).');
        }
    }

    public function test_role_is_organization_data_made_of_permissions(): void
    {
        $organization = Organization::factory()->create();

        $role = $this->create($organization, '  Sekretariat ', ['members.manage', 'members.view', 'members.view']);

        $this->assertSame(['Sekretariat', ['members.manage', 'members.view'], AccessRoleStatus::Active], [$role->name, $role->permissions, $role->status]);
        $this->assertTrue($role->grants(Permission::MembersView));
        $this->assertFalse($role->grants(Permission::RolesAssign));
        $entry = AuditEntry::query()->where('action', 'access_role.created')->sole();
        $this->assertSame([$organization->public_id, 'Nowa rola'], [$entry->organization_id, $entry->reason]);
    }

    public function test_unknown_or_missing_permissions_are_refused(): void
    {
        $organization = Organization::factory()->create();

        foreach ([['members.view', 'events.fly'], []] as $permissions) {
            try {
                $this->create($organization, 'Zła rola', $permissions);
                $this->fail('Invalid permission set must be refused.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty(array_filter(array_keys($e->errors()), fn ($key) => str_starts_with($key, 'permissions')));
            }
        }
        $this->assertSame(0, AccessRole::query()->count());
    }

    public function test_active_role_names_are_unique_per_organization_only(): void
    {
        [$first, $second] = Organization::factory()->count(2)->create()->all();
        $role = $this->create($first);
        $this->create($second);

        try {
            $this->create($first, 'SEKRETARIAT');
            $this->fail('Duplicate active name must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        $this->app->make(RetireAccessRole::class)->handle($role, 'Zastąpiona');
        $this->assertSame(AccessRoleStatus::Active, $this->create($first)->status, 'Nazwę wycofanej roli można użyć ponownie.');
    }

    public function test_changing_permissions_is_audited_with_before_and_after(): void
    {
        $role = $this->create(Organization::factory()->create());

        $this->app->make(UpdateAccessRole::class)->handle($role, 'Sekretariat', ['members.view'], 'Ograniczenie uprawnień');

        $entry = AuditEntry::query()->where('action', 'access_role.updated')->sole();
        $this->assertSame('Ograniczenie uprawnień', $entry->reason);
        $this->assertSame(['members.manage', 'members.view'], json_decode($entry->before_values['permissions'], true));
        $this->assertSame(['members.view'], json_decode($entry->after_values['permissions'], true));
        $this->assertFalse($role->fresh()->grants(Permission::MembersManage));
    }

    public function test_retired_role_grants_nothing_and_cannot_change(): void
    {
        $role = $this->create(Organization::factory()->create());

        $retired = $this->app->make(RetireAccessRole::class)->handle($role, 'Nieużywana');

        $this->assertFalse($retired->grants(Permission::MembersView));
        $this->assertSame($retired->id, $this->app->make(RetireAccessRole::class)->handle($role, 'Ponownie')->id);
        $this->expectException(LogicException::class);
        $this->app->make(UpdateAccessRole::class)->handle($role, 'Nowa nazwa', ['members.view'], 'Próba');
    }

    public function test_role_cannot_move_between_organizations_or_be_deleted(): void
    {
        $role = $this->create(Organization::factory()->create());
        $other = Organization::factory()->create();

        try {
            $this->app->make(AuditReason::class)->because('Próba', fn () => $role->forceFill(['organization_id' => $other->id])->save());
            $this->fail('Role must stay in its organization.');
        } catch (LogicException) {
        }
        try {
            $role->delete();
            $this->fail('Roles are retired, not deleted.');
        } catch (LogicException) {
        }

        $this->expectException(QueryException::class);
        DB::table('access_roles')->where('id', $role->id)->delete();
    }

    public function test_archived_organization_cannot_define_roles(): void
    {
        $organization = Organization::factory()->create();
        $this->asSystem(fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, 'Likwidacja'));

        $this->expectException(ValidationException::class);
        $this->create($organization);
    }
}
