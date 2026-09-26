<?php

namespace Tests\Feature\Domain\Identity;

use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function account(): User
    {
        return User::factory()->create(['email' => 'anna@example.test', 'password' => self::PASSWORD]);
    }

    private function currentCode(User $account): string
    {
        return $this->app->make(Google2FA::class)->getCurrentOtp(decrypt($account->fresh()->two_factor_secret));
    }

    /** A code not used yet: Fortify refuses a replayed code, the next time step is inside the window. */
    private function nextCode(User $account): string
    {
        $totp = $this->app->make(Google2FA::class);

        return $totp->oathTotp(decrypt($account->fresh()->two_factor_secret), $totp->getTimestamp() + 1);
    }

    /** Enables and confirms 2FA through the HTTP endpoints, as the account holder would. */
    private function enableAndConfirm(User $account): User
    {
        $this->actingAs($account)->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson('/user/two-factor-authentication')->assertOk();
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $this->currentCode($account)])->assertOk();
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();

        return $account->fresh();
    }

    public function test_enabling_requires_a_recent_password_confirmation(): void
    {
        $this->actingAs($this->account())->postJson('/user/two-factor-authentication')->assertStatus(423);
        $this->get('/user/confirm-password')->assertOk()->assertSee('Potwierdź hasło');
    }

    public function test_enabled_two_factor_is_active_only_after_confirmation_with_a_code(): void
    {
        $account = $this->account();
        $this->actingAs($account)->withSession(['auth.password_confirmed_at' => time()]);

        $this->postJson('/user/two-factor-authentication')->assertOk();
        $this->assertNotNull($account->fresh()->two_factor_secret);
        $this->assertFalse($account->fresh()->hasConfirmedTwoFactor());
        $this->getJson('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg', 'url']);

        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertUnprocessable();
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $this->currentCode($account)])->assertOk();

        $this->assertTrue($account->fresh()->hasConfirmedTwoFactor());
        $this->assertSame(
            ['two-factor authentication enabled by account holder', 'two-factor authentication confirmed with code'],
            AuditEntry::query()->where('action', 'account.updated')->orderBy('id')->pluck('reason')->all(),
        );
        $this->assertSame('[REDACTED]', AuditEntry::query()->where('action', 'account.updated')->orderBy('id')->first()->after_values['two_factor_secret']);
    }

    public function test_unconfirmed_two_factor_does_not_block_login(): void
    {
        $account = $this->account();
        $this->actingAs($account)->withSession(['auth.password_confirmed_at' => time()])->postJson('/user/two-factor-authentication');
        $this->app['auth']->guard('web')->logout();

        $this->post('/login', ['email' => 'anna@example.test', 'password' => self::PASSWORD])->assertRedirect('/account');
        $this->assertAuthenticatedAs($account);
    }

    public function test_login_with_confirmed_two_factor_requires_the_code(): void
    {
        $account = $this->enableAndConfirm($this->account());

        $this->post('/login', ['email' => 'anna@example.test', 'password' => self::PASSWORD])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertOk()->assertSee('Kod weryfikacyjny');

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => $this->nextCode($account)])->assertRedirect('/account');
        $this->assertAuthenticatedAs($account);
    }

    public function test_recovery_code_logs_in_once_and_is_replaced(): void
    {
        $account = $this->enableAndConfirm($this->account());
        $recovery = $account->recoveryCodes()[0];

        $this->post('/login', ['email' => 'anna@example.test', 'password' => self::PASSWORD]);
        $this->post('/two-factor-challenge', ['recovery_code' => $recovery])->assertRedirect('/account');

        $this->assertAuthenticatedAs($account);
        $this->assertNotContains($recovery, $account->fresh()->recoveryCodes());
        $this->assertCount(8, $account->fresh()->recoveryCodes());
        $this->assertSame(1, AuditEntry::query()->where('reason', 'recovery code used at login')->count());
    }

    public function test_code_attempts_are_limited(): void
    {
        $this->enableAndConfirm($this->account());
        $this->post('/login', ['email' => 'anna@example.test', 'password' => self::PASSWORD]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/two-factor-challenge', ['code' => '00000'.$i]);
        }

        $this->post('/two-factor-challenge', ['code' => '000009'])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_disabling_removes_the_second_factor(): void
    {
        $account = $this->enableAndConfirm($this->account());

        $this->actingAs($account)->withSession(['auth.password_confirmed_at' => time()])->deleteJson('/user/two-factor-authentication')->assertOk();

        $this->assertFalse($account->fresh()->hasConfirmedTwoFactor());
        $this->assertNull($account->fresh()->two_factor_secret);
        $this->assertSame(1, AuditEntry::query()->where('reason', 'two-factor authentication disabled by account holder')->count());
    }

    public function test_mfa_middleware_admits_only_accounts_with_confirmed_two_factor(): void
    {
        Route::middleware(['web', 'auth', 'mfa'])->get('/_probe/admin', fn () => 'admin area');
        $withoutMfa = User::factory()->create();
        $withMfa = $this->enableAndConfirm($this->account());

        $this->actingAs($withoutMfa)->get('/_probe/admin')->assertForbidden();
        $this->flushSession();
        $this->actingAs($withMfa)->get('/_probe/admin')->assertOk()->assertSee('admin area');
        $this->assertSame(1, AuditEntry::on('audit')->where('action', 'access.denied')->count(), 'Odmowa jest audytowana (E1.4).');
    }
}
