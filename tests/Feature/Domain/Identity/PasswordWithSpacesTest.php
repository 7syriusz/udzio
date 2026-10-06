<?php

namespace Tests\Feature\Domain\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Spaces are part of a password (E3.8c): never trimmed at the start, the end or inside — when creating an
 * account, changing, setting (link) or resetting the password, and when logging in. A password that differs
 * only by its spaces is a different password.
 */
class PasswordWithSpacesTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Leading, trailing and inner spaces (also double) and Polish letters. */
    private const PHRASE = '  zielony żółw  pije herbatę ';

    private const OTHER_PHRASE = ' czerwony kot śpi na  parapecie  ';

    private function assertOnlyExactPasswordLogsIn(string $email, string $password): void
    {
        foreach (array_unique([trim($password), ltrim($password), rtrim($password), preg_replace('/\s+/', ' ', $password)]) as $altered) {
            if ($altered === $password) {
                continue;
            }
            $this->post('/login', ['email' => $email, 'password' => $altered])->assertSessionHasErrors('email');
            $this->assertGuest();
        }
        $this->post('/login', ['email' => $email, 'password' => $password])->assertRedirect('/account');
        $this->assertAuthenticated();
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();
    }

    public function test_account_is_created_with_the_password_exactly_as_typed(): void
    {
        $this->post('/register', [
            'given_name' => 'Anna', 'family_name' => 'Nowak', 'email' => 'anna@example.test',
            'password' => self::PHRASE, 'password_confirmation' => self::PHRASE,
        ])->assertRedirect('/account');
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();

        $account = User::query()->where('email', 'anna@example.test')->sole();
        $this->assertTrue(Hash::check(self::PHRASE, $account->password));
        $this->assertFalse(Hash::check(trim(self::PHRASE), $account->password));
        $this->assertOnlyExactPasswordLogsIn('anna@example.test', self::PHRASE);
    }

    public function test_confirmation_must_match_including_spaces(): void
    {
        $this->post('/register', [
            'given_name' => 'Anna', 'family_name' => 'Nowak', 'email' => 'anna@example.test',
            'password' => self::PHRASE, 'password_confirmation' => trim(self::PHRASE),
        ])->assertSessionHasErrors('password');
        $this->assertSame(0, User::query()->count());
    }

    public function test_password_change_keeps_spaces_in_the_current_and_the_new_password(): void
    {
        $account = User::factory()->create(['email' => 'anna@example.test', 'password' => self::PHRASE]);

        $this->actingAs($account)->put('/user/password', ['current_password' => trim(self::PHRASE), 'password' => self::OTHER_PHRASE, 'password_confirmation' => self::OTHER_PHRASE])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');
        $this->actingAs($account)->put('/user/password', ['current_password' => self::PHRASE, 'password' => self::OTHER_PHRASE, 'password_confirmation' => self::OTHER_PHRASE])
            ->assertSessionHasNoErrors();
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();

        $this->assertTrue(Hash::check(self::OTHER_PHRASE, $account->fresh()->password));
        $this->assertOnlyExactPasswordLogsIn('anna@example.test', self::OTHER_PHRASE);
    }

    public function test_password_set_or_reset_by_link_keeps_spaces(): void
    {
        // The same link sets the first password of an installed administrator (E3.8b) and resets a forgotten one.
        $account = User::factory()->create(['email' => 'anna@example.test']);
        $token = Password::broker()->createToken($account);

        $this->post('/reset-password', ['token' => $token, 'email' => 'anna@example.test', 'password' => self::PHRASE, 'password_confirmation' => self::PHRASE])
            ->assertRedirect('/login');

        $this->assertTrue(Hash::check(self::PHRASE, $account->fresh()->password));
        $this->assertOnlyExactPasswordLogsIn('anna@example.test', self::PHRASE);
    }

    public function test_a_password_of_spaces_around_a_short_word_is_still_measured_as_typed(): void
    {
        $padded = '     krótkie     ';
        $this->post('/register', [
            'given_name' => 'Anna', 'family_name' => 'Nowak', 'email' => 'anna@example.test',
            'password' => $padded, 'password_confirmation' => $padded,
        ])->assertRedirect('/account');

        $this->assertTrue(Hash::check($padded, User::query()->sole()->password), 'Długość liczy się ze spacjami; nic nie jest przycinane.');
    }
}
