<?php

namespace Tests\Feature\Domain\Identity;

use App\Domain\Identity\Passwords\BreachedPasswordVerifier;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * E3.8e (Z-041): at least 15 characters as the user sees them, no composition rules, never trimmed; common
 * passwords refused locally, breached ones through Have I Been Pwned without blocking when it is down; new
 * hashes in Argon2id and older bcrypt hashes rehashed on the next successful login.
 */
class PasswordPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOO_SHORT = 'Hasło musi mieć co najmniej 15 znaków. Możesz użyć łatwego do zapamiętania zdania ze spacjami.';

    private const WEAK = 'To hasło jest zbyt popularne albo pojawiło się w wycieku danych. Wybierz inne, najlepiej dłuższą frazę.';

    private const PHRASE = 'zielony żółw pije herbatę';

    /** @return TestResponse<Response> */
    private function register(string $password, string $email = 'anna@example.test')
    {
        return $this->post('/register', ['given_name' => 'Anna', 'family_name' => 'Nowak', 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);
    }

    private function logout(): void
    {
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();
    }

    private function enableBreachCheck(): void
    {
        config(['identity.passwords.breach_check.enabled' => true]);
    }

    /** HIBP range answer containing the password's suffix (as the real service answers, with padding entries). */
    private function breachedResponse(string $password, int $count = 3): string
    {
        $hash = strtoupper(sha1($password));

        return "0018A45C4D1DEF81644B54AB7F969B88D65:0\r\n".substr($hash, 5).":{$count}\r\nFFFFFE3A4C8E1F0A7C25E1E9A7C02C3F1B1:2";
    }

    private function algorithm(User $account): string
    {
        return password_get_info(DB::table('users')->where('id', $account->id)->value('password'))['algoName'];
    }

    public function test_a_phrase_of_15_characters_needs_no_digit_capital_or_symbol(): void
    {
        $this->register(self::PHRASE)->assertRedirect('/account');

        $this->assertTrue(Hash::check(self::PHRASE, User::query()->sole()->password));
    }

    public function test_length_is_counted_in_characters_with_spaces_not_in_bytes(): void
    {
        $fourteen = 'łąka nad rzeką';  // 14 characters, 18 bytes
        $this->assertSame(14, mb_strlen($fourteen));
        $this->register($fourteen)->assertSessionHasErrors(['password' => self::TOO_SHORT]);
        $this->assertSame(0, User::query()->count());

        $paddedToFifteen = ' łąka nad rzeką';  // the leading space is the 15th character
        $this->register($paddedToFifteen)->assertRedirect('/account');
        $this->assertTrue(Hash::check($paddedToFifteen, User::query()->sole()->password), 'Hasło zapisane bez przycinania.');
        $this->assertFalse(Hash::check(trim($paddedToFifteen), User::query()->sole()->password));
    }

    public function test_maximum_is_255_characters_and_long_phrases_are_never_truncated(): void
    {
        $longest = mb_substr(str_repeat('zażółć gęślą jaźń przy kominku ', 10), 0, 255);
        $this->assertGreaterThan(255, strlen($longest), 'Więcej bajtów niż znaków.');
        $this->register($longest.'x', 'za-dlugie@example.test')->assertSessionHasErrors(['password' => 'Hasło może mieć najwyżej 255 znaków.']);

        $this->register($longest)->assertRedirect('/account');
        $this->logout();
        // bcrypt would ignore everything after 72 bytes; Argon2id compares the whole phrase.
        $sameStart = mb_substr($longest, 0, 254).'Q';
        $this->post('/login', ['email' => 'anna@example.test', 'password' => $sameStart])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'anna@example.test', 'password' => $longest])->assertRedirect('/account');
    }

    public function test_common_and_obvious_passwords_are_refused_locally_without_any_request(): void
    {
        Http::fake();
        $this->enableBreachCheck();

        foreach (['Password Password', 'qwertyuiopasdfgh', '123456789012345', 'aaaaaaaaaaaaaaa', 'abcabcabcabcabc', 'Correct Horse Battery Staple', 'zyxwvutsrqponmlk'] as $index => $weak) {
            $this->register($weak, "weak{$index}@example.test")->assertSessionHasErrors(['password' => self::WEAK]);
        }

        $this->assertSame(0, User::query()->count());
        Http::assertNothingSent();
    }

    public function test_breached_password_is_refused_and_only_a_5_character_prefix_leaves_the_server(): void
    {
        $this->enableBreachCheck();
        $breached = 'mój ulubiony pies burek';
        Http::fake([BreachedPasswordVerifier::ENDPOINT.'*' => Http::response($this->breachedResponse($breached, 12345))]);

        $response = $this->register($breached);

        $response->assertSessionHasErrors(['password' => self::WEAK]);
        $this->assertSame(0, User::query()->count());
        $hash = strtoupper(sha1($breached));
        Http::assertSent(fn (Request $request) => $request->url() === BreachedPasswordVerifier::ENDPOINT.substr($hash, 0, 5)
            && ! str_contains($request->url().json_encode($request->headers()).$request->body(), substr($hash, 5)));
        $this->assertStringNotContainsString('12345', implode(' ', session('errors')->all()), 'Bez liczby wystąpień ani nazwy wycieku.');
    }

    public function test_password_not_in_any_breach_is_accepted(): void
    {
        $this->enableBreachCheck();
        Http::fake([BreachedPasswordVerifier::ENDPOINT.'*' => Http::response($this->breachedResponse('zupełnie inne hasło'))]);

        $this->register(self::PHRASE)->assertRedirect('/account');
    }

    public function test_unavailable_breach_service_does_not_block_and_is_logged_without_secrets(): void
    {
        $this->enableBreachCheck();
        Log::spy();
        Http::fake([BreachedPasswordVerifier::ENDPOINT.'*' => fn () => throw new ConnectionException('timeout')]);
        $this->register(self::PHRASE)->assertRedirect('/account');
        $this->logout();

        Http::fake([BreachedPasswordVerifier::ENDPOINT.'*' => Http::response('', 503)]);
        $this->register('drugie zdanie o kocie', 'drugi@example.test')->assertRedirect('/account');
        $this->logout();
        $this->register('Password Password', 'trzeci@example.test')->assertSessionHasErrors(['password' => self::WEAK]);

        $this->assertSame(2, User::query()->count());
        $hash = strtoupper(sha1(self::PHRASE));
        Log::shouldHaveReceived('warning')->twice()->withArgs(function (string $message, array $context) use ($hash): bool {
            $logged = $message.json_encode($context);

            return in_array($context['failure'], [ConnectionException::class, 'http_503'], true)
                && ! str_contains($logged, substr($hash, 0, 5)) && ! str_contains($logged, 'herbat');
        });
    }

    public function test_new_changed_and_reset_passwords_are_stored_as_argon2id(): void
    {
        $this->register(self::PHRASE)->assertRedirect('/account');
        $account = User::query()->sole();
        $this->assertSame('argon2id', $this->algorithm($account));

        $this->put('/user/password', ['current_password' => self::PHRASE, 'password' => 'krótkie', 'password_confirmation' => 'krótkie'])
            ->assertSessionHasErrorsIn('updatePassword', ['password' => self::TOO_SHORT]);
        $this->put('/user/password', ['current_password' => self::PHRASE, 'password' => 'czerwony kot śpi na parapecie', 'password_confirmation' => 'czerwony kot śpi na parapecie'])
            ->assertSessionHasNoErrors();
        $this->assertSame('argon2id', $this->algorithm($account));
        $this->assertTrue(Hash::check('czerwony kot śpi na parapecie', $account->fresh()->password));
        $this->logout();

        $token = Password::broker()->createToken($account);
        $this->post('/reset-password', ['token' => $token, 'email' => 'anna@example.test', 'password' => 'Password Password', 'password_confirmation' => 'Password Password'])
            ->assertSessionHasErrors(['password' => self::WEAK]);
        $this->post('/reset-password', ['token' => $token, 'email' => 'anna@example.test', 'password' => 'niebieski balon nad łąką', 'password_confirmation' => 'niebieski balon nad łąką'])
            ->assertRedirect('/login');
        $this->assertSame('argon2id', $this->algorithm($account));
        $this->assertTrue(Hash::check('niebieski balon nad łąką', $account->fresh()->password));
    }

    public function test_account_with_a_bcrypt_hash_logs_in_and_is_rehashed_to_argon2id(): void
    {
        $account = User::factory()->create(['email' => 'anna@example.test']);
        DB::table('users')->where('id', $account->id)->update(['password' => password_hash('stare hasło bcrypt', PASSWORD_BCRYPT, ['cost' => 12])]);
        $this->assertSame('bcrypt', $this->algorithm($account));

        $this->post('/login', ['email' => 'anna@example.test', 'password' => 'stare hasło bcrypt'])->assertRedirect('/account');

        $this->assertAuthenticatedAs($account);
        $this->assertSame('argon2id', $this->algorithm($account));
        $this->assertTrue(Hash::check('stare hasło bcrypt', $account->fresh()->password));
        $rehash = AuditEntry::query()->where(['action' => 'account.updated', 'reason' => 'password hash recomputed'])->sole();
        $this->assertSame('[REDACTED]', $rehash->after_values['password']);
        $this->assertStringNotContainsString('stare hasło', json_encode(AuditEntry::query()->get()->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_failed_login_does_not_rehash_a_bcrypt_hash(): void
    {
        $account = User::factory()->create(['email' => 'anna@example.test']);
        $bcrypt = password_hash('stare hasło bcrypt', PASSWORD_BCRYPT, ['cost' => 12]);
        DB::table('users')->where('id', $account->id)->update(['password' => $bcrypt]);

        $this->post('/login', ['email' => 'anna@example.test', 'password' => 'złe hasło do konta'])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame($bcrypt, DB::table('users')->where('id', $account->id)->value('password'));
        $this->assertSame(0, AuditEntry::query()->where('reason', 'password hash recomputed')->count());
    }

    public function test_argon2id_parameters_keep_login_fast_and_memory_bounded(): void
    {
        $options = config('hashing.argon');
        $this->assertSame('argon2id', config('hashing.driver'));
        $this->assertGreaterThanOrEqual(19456, $options['memory'], 'Nie słabiej niż zalecenie OWASP (19 MiB).');
        $this->assertLessThanOrEqual(65536, $options['memory'], 'Najwyżej 64 MiB na jedno logowanie.');

        $hash = Hash::make(self::PHRASE);
        $this->assertSame(['memory_cost' => $options['memory'], 'time_cost' => $options['time'], 'threads' => $options['threads']], password_get_info($hash)['options']);
        $started = hrtime(true);
        $this->assertTrue(Hash::check(self::PHRASE, $hash));
        $milliseconds = (hrtime(true) - $started) / 1e6;
        $this->assertLessThan(500, $milliseconds, "Sprawdzenie hasła trwało {$milliseconds} ms.");
    }
}
