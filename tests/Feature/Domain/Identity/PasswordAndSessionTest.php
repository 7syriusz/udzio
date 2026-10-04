<?php

namespace Tests\Feature\Domain\Identity;

use App\Domain\Platform\Models\AuditEntry;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordAndSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const OLD = 'old-password-123';

    private const NEW = 'brand-new-password-456';

    private function account(): User
    {
        return User::factory()->create(['email' => 'anna@example.test', 'password' => self::OLD]);
    }

    private function storeSession(User $account, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $account->id, 'payload' => '', 'last_activity' => time()]);
    }

    public function test_reset_link_request_gives_the_same_answer_for_existing_and_unknown_accounts(): void
    {
        Notification::fake();
        $account = $this->account();

        $this->get('/forgot-password')->assertOk()->assertSee('Nie pamiętam hasła');
        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'anna@example.test']);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test']);
        $throttled = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'anna@example.test']);

        foreach ([$known, $unknown, $throttled] as $response) {
            $response->assertRedirect('/forgot-password')->assertSessionHas('status', __(NeutralPasswordResetLinkResponse::MESSAGE))->assertSessionHasNoErrors();
        }
        Notification::assertSentToTimes($account, ResetPassword::class, 1);
    }

    public function test_reset_sets_the_password_and_ends_every_session(): void
    {
        $account = $this->account();
        $this->storeSession($account, 'session-phone');
        $this->storeSession($account, 'session-laptop');
        $rememberToken = $account->remember_token;
        $token = Password::broker()->createToken($account);

        $this->get("/reset-password/{$token}?email=anna@example.test")->assertOk()->assertSee('Nowe hasło');
        $this->post('/reset-password', ['token' => $token, 'email' => 'anna@example.test', 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertRedirect('/login');

        $account->refresh();
        $this->assertTrue(Hash::check(self::NEW, $account->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $account->id)->count());
        $this->assertNotSame($rememberToken, $account->remember_token);
        $this->assertSame(
            ['password reset by e-mail link', 'sessions ended after password reset', 'remember token rotated after password reset'],
            AuditEntry::query()->where('action', 'account.updated')->orderBy('id')->pluck('reason')->all(),
        );
        $this->post('/login', ['email' => 'anna@example.test', 'password' => self::NEW])->assertRedirect('/account');
    }

    public function test_reset_with_an_invalid_token_changes_nothing(): void
    {
        $account = $this->account();

        $this->post('/reset-password', ['token' => 'forged', 'email' => 'anna@example.test', 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD, $account->fresh()->password));
    }

    public function test_login_attempts_are_limited_per_email_and_address(): void
    {
        $this->account();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'anna@example.test', 'password' => 'wrong-password-'.$i])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => 'anna@example.test', 'password' => self::OLD])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_login_regenerates_the_session_identifier(): void
    {
        $this->account();
        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => 'anna@example.test', 'password' => self::OLD]);

        $this->assertAuthenticated();
        $this->assertNotSame($before, session()->getId());
    }

    public function test_logging_out_other_devices_requires_the_password_and_keeps_the_current_session(): void
    {
        $account = $this->account();
        $this->actingAs($account);
        $this->storeSession($account, 'session-phone');

        $this->from('/')->delete('/user/other-sessions', ['password' => 'wrong-password-1'])->assertSessionHasErrors('password');
        $this->assertSame(1, DB::table('sessions')->where('user_id', $account->id)->count());

        $current = str_pad('currentsession', 40, '0');
        $this->storeSession($account, $current);
        $this->withCookie(config('session.cookie'), $current)->from('/')
            ->delete('/user/other-sessions', ['password' => self::OLD])->assertRedirect('/')->assertSessionHas('status');

        $this->assertSame([$current], DB::table('sessions')->where('user_id', $account->id)->pluck('id')->all());
        $this->assertTrue(Hash::check(self::OLD, $account->fresh()->password), 'Hasło bez zmian, zmienia się tylko jego skrót.');
        $this->assertSame(
            ['password hash recomputed', 'account holder logged out other devices'],
            AuditEntry::query()->where('action', 'account.updated')->orderBy('id')->pluck('reason')->all(),
        );
    }

    public function test_session_with_an_outdated_password_hash_is_logged_out(): void
    {
        $account = $this->account();
        $outdatedHash = $account->password;
        $this->app['auth']->guard('web')->setUser($account);
        $account->forceFill(['password' => Hash::make(self::OLD)]);
        User::withoutEvents(fn () => DB::table('users')->where('id', $account->id)->update(['password' => $account->password]));

        $this->withSession(['password_hash_web' => $outdatedHash])->actingAs($account->fresh())->get('/email/verify')->assertRedirect('/login');

        $this->assertGuest();
    }
}
