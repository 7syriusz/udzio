<?php

namespace Tests\Feature\Http\Account;

use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * E3.10g (uwagi Jakuba): "Weryfikacja dwuetapowa" with Polish states and messages (never Fortify's codes), cancelling
 * an unfinished set-up, "Wyloguj inne urządzenia" explained, a show/hide button for every password field and no
 * technical person identifier in "Moje dane".
 */
class SecurityScreenTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_two_factor_states_and_messages_are_polish_without_technical_codes(): void
    {
        $account = User::factory()->create();
        $this->actingAs($account)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get('/account/security')->assertOk()->assertSee('Weryfikacja dwuetapowa')->assertSee('Wyłączona')
            ->assertSee('6-cyfrowy kod z aplikacji na telefonie')->assertSee('Włącz weryfikację dwuetapową');
        $this->from('/account/security')->post('/user/two-factor-authentication')->assertRedirect('/account/security');
        $this->get('/account/security')->assertOk()
            ->assertSee('Rozpoczęta — czeka na potwierdzenie kodem')->assertSee('Zeskanuj kod QR')->assertSee('Anuluj konfigurację')
            ->assertSee('Zeskanuj kod QR w aplikacji na telefonie i wpisz kod, aby dokończyć włączanie weryfikacji dwuetapowej.')
            ->assertDontSee('two-factor-authentication-enabled')->assertDontSee('two-factor-authentication-confirmed');
    }

    public function test_an_unfinished_set_up_can_be_cancelled_and_started_again_with_a_new_secret(): void
    {
        $account = User::factory()->create();
        $this->actingAs($account)->withSession(['auth.password_confirmed_at' => time()]);
        $this->post('/user/two-factor-authentication');
        $firstSecret = $account->fresh()->two_factor_secret;

        $this->from('/account/security')->delete('/user/two-factor-authentication')->assertRedirect('/account/security');

        $this->assertNull($account->fresh()->two_factor_secret);
        $this->assertNull($account->fresh()->two_factor_recovery_codes);
        $this->get('/account/security')->assertSee('Wyłączona')->assertSee('Weryfikacja dwuetapowa jest wyłączona.');
        $this->assertSame(1, AuditEntry::query()->where(['action' => 'account.updated', 'reason' => 'two-factor authentication disabled by account holder'])->count());
        $this->post('/user/two-factor-authentication');
        $this->assertNotSame(decrypt($firstSecret), decrypt($account->fresh()->two_factor_secret), 'Nowy QR po ponownym rozpoczęciu.');
    }

    public function test_logging_out_other_devices_is_named_and_explained(): void
    {
        $this->actingAs(User::factory()->create())->get('/account/security')
            ->assertSee('Wyloguj inne urządzenia')->assertSee('To urządzenie pozostanie zalogowane.')->assertDontSee('Pozostałe urządzenia');
    }

    public function test_every_password_field_has_its_own_hidden_by_default_toggle(): void
    {
        $account = User::factory()->create(['email' => 'anna@example.test']);
        $pages = [
            '/register' => ['password', 'password_confirmation'],
            '/login' => ['password'],
            '/reset-password/'.Password::broker()->createToken($account).'?email=anna@example.test' => ['password', 'password_confirmation'],
        ];
        foreach ($pages as $url => $fields) {
            $this->assertToggles($this->get($url)->assertOk()->getContent(), $fields, $url);
        }
        $this->actingAs($account);
        $this->assertToggles($this->get('/account/security')->getContent(), ['current_password', 'password', 'password_confirmation', 'other-password'], 'security');
        $this->assertToggles($this->get('/user/confirm-password')->getContent(), ['password'], 'confirm-password');
    }

    public function test_my_data_has_no_technical_person_identifier(): void
    {
        $account = User::factory()->create();
        $person = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Anna', 'family_name' => 'Nowak']);
        $this->app->make(AuditReason::class)->because('link', fn () => $account->forceFill(['person_id' => $person->id])->save());

        $this->actingAs($account)->get('/account')->assertOk()->assertSee('Nowak')->assertDontSee($person->public_id)->assertDontSee('Identyfikator osoby');
    }

    /** @param list<string> $fields */
    private function assertToggles(string $page, array $fields, string $where): void
    {
        foreach ($fields as $field) {
            $this->assertMatchesRegularExpression('/<input id="'.preg_quote($field, '/').'"[^>]*type="password"/', $page, "{$where}: pole {$field} ukryte domyślnie");
            $this->assertMatchesRegularExpression('/<button type="button" hidden data-password-toggle aria-controls="'.preg_quote($field, '/').'" aria-pressed="false"\s+aria-label="Pokaż hasło"/', $page, "{$where}: przycisk dla {$field}");
        }
        $this->assertSame(count($fields), substr_count($page, 'data-password-toggle'), "{$where}: jeden przycisk na pole");
    }
}
