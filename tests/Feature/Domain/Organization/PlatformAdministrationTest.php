<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\PlatformAccess;
use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Access\SystemPurpose;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\CreateOrganization;
use App\Domain\Organization\Actions\GrantPlatformRole;
use App\Domain\Organization\Actions\InstallFirstAdministrator;
use App\Domain\Organization\Actions\ResetAccountMfa;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\PlatformInstallation;
use App\Domain\Organization\Models\PlatformRoleAssignment;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Closure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\AnyScopeTestPurpose;
use Tests\TestCase;

/**
 * E3.8b: the first platform administrator comes only from the one-time installation, platform administration
 * is separate from organization administration, works only with a verified e-mail and MFA, and MFA reset is
 * a separate, audited permission that nobody uses on their own account.
 */
class PlatformAdministrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function install(string $email = 'admin@example.test'): int
    {
        return $this->artisan('platform:install-administrator', ['email' => $email, '--given-name' => 'Ada', '--family-name' => 'Nowak'])->run();
    }

    private function as(User $account, Closure $operation): mixed
    {
        return $this->app->make(ActorContext::class)->runAs(Actor::account((string) $account->id), $operation);
    }

    private function decide(User $account, PlatformPermission $permission): string
    {
        return $this->app->make(PlatformAccess::class)->decide($account->fresh(), $permission)->reason;
    }

    /** A platform administrator with a verified e-mail and MFA, made through the installation. */
    private function administrator(): User
    {
        Notification::fake();
        $this->install();
        $administrator = User::query()->where('email', 'admin@example.test')->sole();
        $administrator->markEmailAsVerified();
        $this->setMfa($administrator, true);

        return $administrator->fresh();
    }

    /** Confirmed MFA on (as after set-up, E2.6) or off, with the audit reason every account change needs. */
    private function setMfa(User $account, bool $enabled): void
    {
        $state = $enabled
            ? User::factory()->withTwoFactor()->make()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'])
            : ['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null];
        $this->app->make(AuditReason::class)->because('test: MFA state', fn () => $account->forceFill($state)->save());
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

    public function test_registration_never_creates_a_platform_administrator(): void
    {
        $this->post('/register', ['given_name' => 'Pierwsza', 'family_name' => 'Osoba', 'email' => 'pierwsza@example.test', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery']);
        $first = User::query()->where('email', 'pierwsza@example.test')->sole();
        $first->markEmailAsVerified();

        $this->assertSame(0, PlatformRoleAssignment::query()->count(), 'Pierwszy zarejestrowany nie zostaje administratorem.');
        $this->assertFalse(PlatformInstallation::completed());
        foreach ([PlatformPermission::AdministratorsManage, PlatformPermission::MfaReset] as $permission) {
            $this->assertSame('no_active_assignment', $this->decide($first, $permission));
        }
    }

    public function test_installation_creates_the_first_administrator_once_without_a_password(): void
    {
        Notification::fake();

        $this->artisan('platform:install-administrator', ['email' => 'Admin@Example.test', '--given-name' => 'Ada', '--family-name' => 'Nowak'])
            ->expectsOutputToContain('Utworzono konto pierwszego administratora platformy: admin@example.test')
            ->expectsOutputToContain('dopiero po potwierdzeniu adresu e-mail i włączeniu uwierzytelniania dwuskładnikowego')
            ->assertSuccessful();

        $administrator = User::query()->sole();
        $this->assertNull($administrator->email_verified_at);
        $this->assertFalse($administrator->hasConfirmedTwoFactor());
        foreach (['', 'password', 'admin', 'administrator', 'udzio', 'admin@example.test', 'changeme'] as $guess) {
            $this->assertFalse(Hash::check($guess, $administrator->password), 'Brak hasła domyślnego.');
        }
        Notification::assertSentTo($administrator, ResetPassword::class);
        Notification::assertSentTo($administrator, VerifyEmail::class);
        $this->assertSame('administrator', PlatformRoleAssignment::query()->where('user_id', $administrator->id)->sole()->role);
        $this->assertSame($administrator->id, PlatformInstallation::query()->sole()->administrator_user_id);
        $this->assertSame(0, DB::table('organizations')->count(), 'Instalacja nie tworzy organizacji ani danych przykładowych.');

        $this->assertSame(['purpose' => 'platform.install', 'permissions' => ['platform.install'], 'organizations' => []], array_intersect_key(
            AuditEntry::on('audit')->where('action', 'system_authority.entered')->sole()->after_values, array_flip(['purpose', 'permissions', 'organizations'])));
        $this->assertSame('system_authority', AuditEntry::query()->where(['action' => 'access.granted', 'subject_id' => 'platform.install'])->sole()->after_values['reason']);
        $installed = AuditEntry::query()->where('action', 'platform.installed')->sole();
        $this->assertSame($administrator->id, $installed->after_values['administrator_account_id']);
        $this->assertSame('process', $installed->actor_type->value);
        $this->assertSame(1, AuditEntry::query()->where(['action' => 'account.created', 'reason' => 'platform installation: first administrator'])->count());
        $this->assertSame(1, AuditEntry::query()->where(['action' => 'platform_role_assignment.created'])->count());

        $this->artisan('platform:install-administrator', ['email' => 'drugi@example.test', '--given-name' => 'Jan', '--family-name' => 'Kowal'])
            ->expectsOutputToContain('Platforma jest już zainstalowana.')
            ->assertFailed();
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, PlatformRoleAssignment::query()->count());
        $this->assertSame('installation_completed', AuditEntry::on('audit')->where('action', 'access.denied')->sole()->after_values['reason']);
    }

    public function test_installation_never_promotes_an_existing_account(): void
    {
        Notification::fake();
        $squatter = User::factory()->create(['email' => 'admin@example.test']);

        $this->artisan('platform:install-administrator', ['email' => 'admin@example.test', '--given-name' => 'Ada', '--family-name' => 'Nowak'])
            ->expectsOutputToContain('Pod tym adresem istnieje już konto.')
            ->assertFailed();

        $this->assertSame(0, PlatformRoleAssignment::query()->where('user_id', $squatter->id)->count());
        $this->assertFalse(PlatformInstallation::completed(), 'Nieudana próba nie zamyka instalacji.');
        $this->assertSame(0, $this->install('nowy@example.test'));
    }

    public function test_installation_needs_its_own_purpose_and_system_authority_reaches_nothing_else(): void
    {
        $install = fn () => $this->app->make(InstallFirstAdministrator::class)->handle('admin@example.test', 'Ada', 'Nowak');
        $account = User::factory()->withTwoFactor()->create();
        $authority = $this->app->make(SystemAuthority::class);

        $this->assertDenied(fn () => $this->as($account, $install), 'installation_only');
        $this->assertDenied($install, 'actor_without_account');
        $this->assertDenied(fn () => $authority->run(new AnyScopeTestPurpose, $install), 'system_purpose_permission_missing');

        Notification::fake();
        $purpose = new SystemPurpose('platform.install', 'Instalacja', [PlatformPermission::InstallFirstAdministrator], []);
        $administrator = $authority->run($purpose, $install);
        $this->assertDenied(fn () => $authority->run($purpose, fn () => $this->app->make(GrantPlatformRole::class)->handle($account, 'administrator', 'Obejście')), 'system_authority_not_for_platform');
        $this->assertDenied(fn () => $authority->run($purpose, fn () => $this->app->make(ResetAccountMfa::class)->handle($administrator, 'brak', 'Obejście')), 'system_authority_not_for_platform');
        $this->assertDenied(fn () => $authority->run($purpose, $install), 'installation_completed');
        $this->assertNull($authority->activePurpose(), 'Tryb systemowy kończy się razem z operacją.');
    }

    public function test_there_is_no_seeder_demo_account_or_default_password(): void
    {
        $this->seed();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, PlatformRoleAssignment::query()->count());
        $this->assertFalse(PlatformInstallation::completed());
        $this->assertDoesNotMatchRegularExpression('/(ADMIN|DEFAULT|DEMO)_[A-Z_]*(PASSWORD|EMAIL)/', (string) file_get_contents(base_path('.env.example')));
        $this->assertFalse($this->app->make(Kernel::class)->all()['platform:install-administrator']->getDefinition()->hasOption('password'));
    }

    public function test_administrator_rights_start_only_after_email_verification_and_mfa(): void
    {
        Notification::fake();
        $this->install();
        $administrator = User::query()->sole();
        $candidate = User::factory()->create();

        $this->assertSame('email_unverified', $this->decide($administrator, PlatformPermission::AdministratorsManage));
        $this->assertDenied(fn () => $this->as($administrator, fn () => $this->app->make(GrantPlatformRole::class)->handle($candidate, 'administrator', 'Drugi administrator')), 'email_unverified');

        $administrator->markEmailAsVerified();
        $this->assertSame('mfa_required', $this->decide($administrator, PlatformPermission::AdministratorsManage));
        $denied = $this->assertDenied(fn () => $this->as($administrator, fn () => $this->app->make(GrantPlatformRole::class)->handle($candidate, 'administrator', 'Drugi administrator')), 'mfa_required');
        $this->assertSame('access.mfa_required', $denied->getMessage());

        $this->setMfa($administrator, true);
        $this->assertSame('assignment', $this->decide($administrator, PlatformPermission::AdministratorsManage));
        $this->as($administrator, fn () => $this->app->make(GrantPlatformRole::class)->handle($candidate, 'administrator', 'Drugi administrator'));
        $this->assertSame(1, PlatformRoleAssignment::query()->where('user_id', $candidate->id)->count());
    }

    public function test_organization_administrator_never_gets_platform_permissions_and_the_other_way_round(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->as($founder, fn () => $this->app->make(CreateOrganization::class)->handle('Fundacja'));
        $this->app->make(SystemAuthority::class)->run(new AnyScopeTestPurpose, function () use ($founder, $organization): void {
            $everything = $this->app->make(CreateAccessRole::class)->handle($organization, 'Administrator', array_map(fn (Permission $p) => $p->value, Permission::cases()), 'Założyciel');
            $this->app->make(AssignRole::class)->handle($founder, $everything, $organization, ScopeInheritance::UnitAndDescendants, null, 'Założyciel');
        });
        $this->travel(1)->second();

        $this->assertSame('assignment', $this->app->make(AccessDecider::class)->decide($founder, Permission::RolesManage, $organization)->reason);
        foreach (PlatformPermission::cases() as $permission) {
            $this->assertNotSame('assignment', $this->decide($founder, $permission), $permission->value);
        }
        $this->assertDenied(fn () => $this->as($founder, fn () => $this->app->make(ResetAccountMfa::class)->handle(User::factory()->withTwoFactor()->create(), 'Dokument', 'Utrata telefonu')), 'no_active_assignment');

        $administrator = $this->administrator();
        $this->assertSame('no_active_assignment', $this->app->make(AccessDecider::class)->decide($administrator, Permission::OrganizationView, $organization)->reason, 'Administrator platformy nie ma automatycznie praw w organizacji.');
    }

    public function test_mfa_reset_ends_sessions_and_recovery_codes_and_is_audited(): void
    {
        $administrator = $this->administrator();
        $holder = User::factory()->withTwoFactor()->create();
        $oldCodes = $holder->recoveryCodes();
        $rememberToken = $holder->remember_token;
        DB::table('sessions')->insert([
            ['id' => str_repeat('a', 40), 'user_id' => $holder->id, 'payload' => '', 'last_activity' => time()],
            ['id' => str_repeat('b', 40), 'user_id' => $holder->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->as($administrator, fn () => $this->app->make(ResetAccountMfa::class)->handle($holder, 'Dowód osobisty sprawdzony podczas wideorozmowy', 'Utrata telefonu'));

        $holder->refresh();
        $this->assertFalse($holder->hasConfirmedTwoFactor());
        $this->assertNull($holder->two_factor_recovery_codes);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $holder->id)->count());
        $this->assertNotSame($rememberToken, $holder->remember_token);
        $this->post('/login', ['email' => $holder->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $oldCodes[0]]);
        $this->assertNull($holder->fresh()->two_factor_recovery_codes, 'Dawne kody odzyskiwania nie działają.');

        $entry = AuditEntry::query()->where('action', 'account.mfa_reset')->sole();
        $this->assertSame((string) $administrator->id, $entry->actor_id);
        $this->assertSame((string) $holder->id, $entry->subject_id);
        $this->assertSame('Utrata telefonu', $entry->reason);
        $this->assertSame('Dowód osobisty sprawdzony podczas wideorozmowy', $entry->after_values['identity_confirmation']);
        $this->assertSame(2, $entry->after_values['sessions_ended']);
        $this->assertSame(1, AuditEntry::query()->where(['action' => 'access.granted', 'subject_type' => 'account', 'subject_id' => (string) $holder->id])->count());
        $this->assertSame('[REDACTED]', AuditEntry::query()->where(['action' => 'account.updated', 'reason' => 'Utrata telefonu'])->sole()->before_values['two_factor_secret']);
    }

    public function test_mfa_reset_needs_the_permission_an_identity_confirmation_and_someone_else(): void
    {
        $administrator = $this->administrator();
        $holder = User::factory()->withTwoFactor()->create();
        $reset = fn (User $actor, User $account, string $confirmation = 'Dokument sprawdzony') => $this->as($actor, fn () => $this->app->make(ResetAccountMfa::class)->handle($account, $confirmation, 'Utrata telefonu'));

        $this->assertDenied(fn () => $reset($holder, $holder), 'no_active_assignment');
        $this->assertDenied(fn () => $reset($administrator, $administrator), 'own_account');
        $this->assertTrue($administrator->fresh()->hasConfirmedTwoFactor(), 'Administrator nie resetuje własnego MFA swoją rolą.');
        try {
            $reset($administrator, $holder, '  ');
            $this->fail('Oczekiwano wymogu potwierdzenia tożsamości.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('identity_confirmation', $e->errors());
        }
        $this->assertTrue($holder->fresh()->hasConfirmedTwoFactor());

        $this->setMfa($administrator, false);
        $this->assertDenied(fn () => $reset($administrator, $holder), 'mfa_required');
        $this->assertTrue($holder->fresh()->hasConfirmedTwoFactor());
        $this->assertSame(0, AuditEntry::query()->where('action', 'account.mfa_reset')->count());
    }

    public function test_granting_a_platform_role_to_oneself_is_refused(): void
    {
        $administrator = $this->administrator();

        $this->assertDenied(fn () => $this->as($administrator, fn () => $this->app->make(GrantPlatformRole::class)->handle($administrator, 'administrator', 'Ponownie')), 'own_account');
    }

    public function test_denial_messages_are_polish_without_technical_details(): void
    {
        Route::middleware('web')->post('/_probe/mfa-reset', fn () => $this->app->make(ResetAccountMfa::class)->handle(User::query()->findOrFail(request('account')), 'Dokument', 'Utrata') ?? 'ok');
        config(['app.debug' => false]);
        Notification::fake();
        $this->install();
        $administrator = User::query()->sole();
        $administrator->markEmailAsVerified();
        $holder = User::factory()->withTwoFactor()->create();

        $mfa = $this->actingAs($administrator)->postJson('/_probe/mfa-reset', ['account' => $holder->id]);
        $mfa->assertForbidden()->assertExactJson(['message' => 'Ta czynność wymaga uwierzytelniania dwuskładnikowego. Włącz je w ustawieniach bezpieczeństwa konta, a uprawnienia zaczną działać.']);
        $this->flushSession();
        $plain = $this->actingAs($holder)->postJson('/_probe/mfa-reset', ['account' => $administrator->id]);
        $plain->assertForbidden()->assertExactJson(['message' => 'Nie masz uprawnień do wykonania tej czynności.']);
        foreach (['platform.mfa.reset', 'mfa_required', 'no_active_assignment', 'administrator'] as $secret) {
            $this->assertStringNotContainsString($secret, $mfa->getContent().$plain->getContent());
        }
    }
}
