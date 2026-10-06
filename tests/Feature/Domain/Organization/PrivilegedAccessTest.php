<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\UpdateAccessRole;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\Fixtures\AnyScopeTestPurpose;
use Tests\TestCase;

/**
 * E3.8: a role assignment gives nothing until the account has a verified e-mail, and a privileged one (role
 * policy `requires_mfa` or a privileged permission — never the role's name) until MFA is confirmed.
 */
class PrivilegedAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $company;

    private AccessRole $participant;

    private AccessRole $hrManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Organization::factory()->create();
        $this->system(function (): void {
            $create = $this->app->make(CreateAccessRole::class);
            $this->participant = $create->handle($this->company, 'Uczestnik', ['organization.view', 'members.view'], 'Rola');
            $this->hrManager = $create->handle($this->company, 'Kadry', ['members.view', 'roles.assign'], 'Rola', [
                ['role' => $this->participant->public_id, 'include_descendants' => true],
            ]);
        });
    }

    private function system(Closure $operation): mixed
    {
        return $this->app->make(SystemAuthority::class)->run(new AnyScopeTestPurpose, $operation);
    }

    private function grant(User $account, AccessRole $role): RoleAssignment
    {
        $assignment = $this->system(fn () => $this->app->make(AssignRole::class)->handle($account, $role, $this->company, ScopeInheritance::UnitAndDescendants, null, 'Nadanie'));
        $this->travel(1)->second();

        return $assignment;
    }

    private function decide(User $account, Permission $permission): string
    {
        return $this->app->make(AccessDecider::class)->decide($account->fresh(), $permission, $this->company)->reason;
    }

    private function assignAs(User $actor, User $grantee): RoleAssignment
    {
        return $this->app->make(ActorContext::class)->runAs(Actor::account((string) $actor->id),
            fn () => $this->app->make(AssignRole::class)->handle($grantee, $this->participant, $this->company, ScopeInheritance::UnitOnly, null, 'Nadanie roli'));
    }

    /** Enables and confirms MFA through the account holder's endpoints (E2.6). */
    private function setUpMfa(User $account): void
    {
        $this->actingAs($account)->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson('/user/two-factor-authentication')->assertOk();
        $code = $this->app->make(Google2FA::class)->getCurrentOtp(decrypt($account->fresh()->two_factor_secret));
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $code])->assertOk();
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();
        $this->travel(1)->second();
    }

    public function test_unverified_email_blocks_every_permission_of_an_existing_assignment(): void
    {
        $account = User::factory()->unverified()->withTwoFactor()->create();
        $assignment = $this->grant($account, $this->participant);

        $this->assertTrue($assignment->exists, 'Przypisanie może istnieć.');
        $this->assertSame('email_unverified', $this->decide($account, Permission::MembersView));
        $this->assertSame([], $this->app->make(AccessDecider::class)->grantedOrganizationIds($account, Permission::MembersView));

        $account->markEmailAsVerified();
        $this->assertSame('assignment', $this->decide($account, Permission::MembersView));
    }

    public function test_missing_mfa_blocks_privileged_permissions_until_mfa_is_set_up(): void
    {
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        $this->grant($manager, $this->hrManager);

        $this->assertSame('mfa_required', $this->decide($manager, Permission::RolesAssign));
        $this->assertSame('mfa_required', $this->decide($manager, Permission::MembersView), 'Cała rola uprzywilejowana jest nieaktywna, nie tylko jej uprzywilejowane uprawnienie.');
        try {
            $this->assignAs($manager, $employee);
            $this->fail('Oczekiwano odmowy bez MFA.');
        } catch (AccessDenied $denied) {
            $this->assertSame('access.mfa_required', $denied->getMessage());
        }
        $denial = AuditEntry::on('audit')->where('action', 'access.denied')->sole();
        $this->assertSame('mfa_required', $denial->after_values['reason']);
        $this->assertSame((string) $manager->id, $denial->actor_id);

        $this->setUpMfa($manager);

        $this->assertSame('assignment', $this->decide($manager, Permission::RolesAssign));
        $this->assertTrue($this->assignAs($manager, $employee)->exists, 'Po skonfigurowaniu MFA uprawnienie działa.');
    }

    public function test_ordinary_participant_roles_do_not_require_mfa(): void
    {
        $member = User::factory()->create();
        $this->grant($member, $this->participant);

        $this->assertSame('assignment', $this->decide($member, Permission::MembersView));
        $this->assertSame('assignment', $this->decide($member, Permission::OrganizationView));
    }

    public function test_mfa_follows_the_role_policy_and_permissions_never_its_name(): void
    {
        [$named, $policy, $privileged] = $this->system(fn () => [
            $this->app->make(CreateAccessRole::class)->handle($this->company, 'Administrator', ['organization.view', 'members.view'], 'Rola'),
            $this->app->make(CreateAccessRole::class)->handle($this->company, 'Pomocnik', ['organization.view'], 'Rola', requiresMfa: true),
            $this->app->make(CreateAccessRole::class)->handle($this->company, 'Pomocnik eksportu', ['data.export'], 'Rola'),
        ]);
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        $this->grant($a, $named);
        $this->grant($b, $policy);
        $this->grant($c, $privileged);

        $this->assertSame('assignment', $this->decide($a, Permission::OrganizationView), 'Nazwa „Administrator” nie wymaga MFA.');
        $this->assertSame('mfa_required', $this->decide($b, Permission::OrganizationView), 'Polityka roli wymaga MFA.');
        $this->assertSame('mfa_required', $this->decide($c, Permission::DataExport), 'Uprawnienie uprzywilejowane wymaga MFA.');

        $this->system(fn () => $this->app->make(UpdateAccessRole::class)->handle($named, 'Administrator', ['organization.view', 'members.view'], 'Zaostrzenie', requiresMfa: true));
        $this->travel(1)->second();
        $this->assertSame('mfa_required', $this->decide($a, Permission::OrganizationView), 'Zmiana polityki działa od nowej wersji roli.');
        $this->assertTrue($named->fresh()->versionAt(now())->content['requires_mfa']);
        $change = AuditEntry::query()->where(['action' => 'access_role.updated', 'reason' => 'Zaostrzenie'])->sole();
        $this->assertFalse((bool) $change->before_values['requires_mfa']);
        $this->assertTrue((bool) $change->after_values['requires_mfa']);
    }

    public function test_privileged_permission_list_is_configuration(): void
    {
        $member = User::factory()->create();
        $this->grant($member, $this->participant);

        config(['organization.privileged_access.permissions' => ['members.view']]);

        $this->assertSame('mfa_required', $this->decide($member, Permission::OrganizationView));
    }

    public function test_disabling_mfa_switches_privileged_permissions_off_again(): void
    {
        $manager = User::factory()->withTwoFactor()->create();
        $this->grant($manager, $this->hrManager);
        $this->assertSame('assignment', $this->decide($manager, Permission::RolesAssign));

        $this->actingAs($manager)->withSession(['auth.password_confirmed_at' => time()])->deleteJson('/user/two-factor-authentication')->assertOk();

        $this->assertSame('mfa_required', $this->decide($manager, Permission::RolesAssign));
    }

    public function test_user_gets_a_polish_message_leading_to_mfa_set_up_without_technical_details(): void
    {
        Route::middleware('web')->post('/_probe/assign', fn () => $this->assignAs(request()->user(), User::query()->findOrFail(request('grantee'))) ? 'ok' : 'no');
        config(['app.debug' => false]);
        $manager = User::factory()->create();
        $this->grant($manager, $this->hrManager);
        $employee = User::factory()->create();

        $json = $this->actingAs($manager)->postJson('/_probe/assign', ['grantee' => $employee->id]);
        $json->assertForbidden()->assertExactJson(['message' => 'Ta czynność wymaga uwierzytelniania dwuskładnikowego. Włącz je w ustawieniach bezpieczeństwa konta, a uprawnienia zaczną działać.']);

        $html = $this->post('/_probe/assign', ['grantee' => $employee->id]);
        $html->assertForbidden()->assertSee('Ta czynność wymaga uwierzytelniania dwuskładnikowego.')->assertSee(route('account.security'));
        foreach (['mfa_required', 'Kadry', $this->hrManager->public_id, 'roles.assign'] as $secret) {
            $this->assertStringNotContainsString($secret, $json->getContent().$html->getContent());
        }
    }
}
