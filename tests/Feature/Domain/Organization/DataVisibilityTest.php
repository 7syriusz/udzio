<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Identity\Actions\GrantRepresentation;
use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\DataVisibility;
use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Actions\AdmitMember;
use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\AnyScopeTestPurpose;
use Tests\Support\RunsAsSystem;
use Tests\TestCase;

class DataVisibilityTest extends TestCase
{
    use LazilyRefreshDatabase, RunsAsSystem;

    private Organization $clubA;

    private Organization $sectionA;

    private Organization $clubB;

    private User $adminA;

    private Person $shared;

    private Person $onlyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
        [$this->clubA, $this->sectionA, $this->clubB] = Organization::factory()->count(3)->create()->all();
        $this->asSystem(fn () => $this->app->make(MoveOrganization::class)->handle($this->sectionA, $this->clubA, 'Struktura'));
        $this->shared = $this->person('Anna');
        $this->onlyB = $this->person('Bartek');
        $admit = $this->app->make(AdmitMember::class);
        $admit->handle($this->shared, $this->sectionA, 'member', 'Przyjęcie');
        $admit->handle($this->shared, $this->clubB, 'treasurer', 'Przyjęcie');
        $admit->handle($this->onlyB, $this->clubB, 'member', 'Przyjęcie');
        $this->adminA = $this->administrator($this->clubA, ScopeInheritance::UnitAndDescendants);
        $this->travel(1)->minute();
    }

    private function person(string $given): Person
    {
        return $this->app->make(RegisterPerson::class)->handle(['given_name' => $given, 'family_name' => 'Nowak']);
    }

    private function administrator(Organization $scope, ScopeInheritance $inheritance): User
    {
        $account = User::factory()->create();
        $this->app->make(SystemAuthority::class)->run(new AnyScopeTestPurpose, function () use ($account, $scope, $inheritance): void {
            $role = $this->app->make(CreateAccessRole::class)->handle($scope, 'Administrator '.$scope->id.' '.$account->id, ['organization.view', 'members.view'], 'Rola');
            $this->app->make(AssignRole::class)->handle($account, $role, $scope, $inheritance, null, 'Nadanie');
        });

        return $account;
    }

    private function visibility(): DataVisibility
    {
        return $this->app->make(DataVisibility::class);
    }

    public function test_administrator_lists_only_organizations_in_scope(): void
    {
        $this->assertEqualsCanonicalizing([$this->clubA->id, $this->sectionA->id], $this->visibility()->organizations($this->adminA)->pluck('id')->all());
        $this->assertSame([], $this->visibility()->organizations(User::factory()->create())->pluck('id')->all(), 'Konto bez ról nie widzi nic.');
    }

    public function test_person_in_two_organizations_is_one_identity_but_each_side_sees_only_its_memberships(): void
    {
        $adminB = $this->administrator($this->clubB, ScopeInheritance::UnitOnly);

        $membershipsA = $this->visibility()->memberships($this->adminA)->get();
        $this->assertSame([$this->sectionA->id], $membershipsA->pluck('organization_id')->all());
        $this->assertSame(['member'], $membershipsA->pluck('function')->all(), 'Funkcja w obcej organizacji jest niewidoczna.');
        $this->assertSame([$this->shared->id], $this->visibility()->people($this->adminA)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->shared->id, $this->onlyB->id], $this->visibility()->people($adminB)->pluck('id')->all());
        $this->assertSame(2, Person::query()->count(), 'Jedna globalna PERSON, bez duplikatów (A5-01).');
    }

    public function test_unit_only_administrator_does_not_see_units_below(): void
    {
        $clubOnly = $this->administrator($this->clubA, ScopeInheritance::UnitOnly);

        $this->assertSame([$this->clubA->id], $this->visibility()->organizations($clubOnly)->pluck('id')->all());
        $this->assertSame([], $this->visibility()->memberships($clubOnly)->pluck('id')->all());
        $this->assertSame([], $this->visibility()->people($clubOnly)->pluck('id')->all());
    }

    public function test_reading_outside_the_scope_answers_not_found_and_is_audited(): void
    {
        foreach ([
            ['organization', fn () => $this->visibility()->findOrganization($this->adminA, $this->clubB->public_id), $this->clubB->public_id],
            ['person', fn () => $this->visibility()->findPerson($this->adminA, $this->onlyB->public_id), $this->onlyB->public_id],
        ] as [$type, $read, $publicId]) {
            try {
                $read();
                $this->fail('Foreign data must be hidden.');
            } catch (AccessDenied $denied) {
                $this->assertSame(404, $denied->status());
                $this->assertTrue($denied->recorded);
            }
            $this->assertSame(1, AuditEntry::on('audit')->where(['action' => 'access.denied', 'subject_type' => $type, 'subject_id' => $publicId])->count());
        }

        $this->assertTrue($this->visibility()->findPerson($this->adminA, $this->shared->public_id)->is($this->shared));
        $this->assertTrue($this->visibility()->findOrganization($this->adminA, $this->sectionA->public_id)->is($this->sectionA));
    }

    public function test_own_and_represented_people_are_visible_without_any_role(): void
    {
        $parent = $this->person('Maria');
        $child = $this->person('Zosia');
        $account = User::factory()->create();
        $this->app->make(AuditReason::class)->because('link', fn () => $account->forceFill(['person_id' => $parent->id])->save());
        $this->app->make(GrantRepresentation::class)->handle($parent, $child, [RepresentationScope::ProfileView], RepresentationMethod::Document, 'akt urodzenia', now()->subMinute(), 'Opiekun');

        $this->assertEqualsCanonicalizing([$parent->id, $child->id], $this->visibility()->people($account)->pluck('id')->all());
        $this->assertSame([], $this->visibility()->memberships($account)->pluck('id')->all(), 'Reprezentacja nie daje wglądu w dane organizacji.');
    }

    public function test_archived_unit_drops_out_of_visibility(): void
    {
        $this->asSystem(fn () => $this->app->make(ArchiveOrganization::class)->handle($this->sectionA, 'Likwidacja sekcji'));

        $this->assertSame([$this->clubA->id], $this->visibility()->organizations($this->adminA)->pluck('id')->all());
        $this->assertSame([], $this->visibility()->people($this->adminA)->pluck('id')->all());
    }

    public function test_visible_units_agree_with_single_decisions(): void
    {
        $decider = $this->app->make(AccessDecider::class);
        $granted = $decider->grantedOrganizationIds($this->adminA, Permission::MembersView);

        foreach (Organization::query()->get() as $organization) {
            $this->assertSame(
                in_array($organization->id, $granted, true),
                $decider->decide($this->adminA, Permission::MembersView, $organization)->allowed,
                'Zbiór widoczności i pojedyncza decyzja muszą być zgodne dla '.$organization->public_id,
            );
        }
    }

    public function test_http_read_outside_the_scope_is_404_and_audited_once(): void
    {
        Route::middleware('web')->get('/_probe/people/{publicId}', fn (string $publicId) => app(DataVisibility::class)->findPerson(request()->user(), $publicId)->given_name);

        $this->actingAs($this->adminA)->get('/_probe/people/'.$this->shared->public_id)->assertOk()->assertSee('Anna');
        $this->actingAs($this->adminA)->get('/_probe/people/'.$this->onlyB->public_id)->assertNotFound()->assertDontSee('Bartek');

        $this->assertSame(1, AuditEntry::on('audit')->where('action', 'access.denied')->count());
    }
}
