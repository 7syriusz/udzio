<?php

namespace Tests\Feature;

use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Localization\LocaleResolver;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** E3.6b: Polish as default and fallback, one place choosing the language, no hardcoded user texts. */
class LocalizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function resolver(): LocaleResolver
    {
        return $this->app->make(LocaleResolver::class);
    }

    public function test_polish_is_the_default_and_the_fallback_language(): void
    {
        $this->assertSame('pl', config('app.locale'));
        $this->assertSame('pl', config('app.fallback_locale'));
        $this->assertSame('pl', config('localization.default'));

        $this->get('/login')->assertOk()->assertSee('<html lang="pl">', false)->assertSee('Logowanie');
    }

    public function test_without_language_variables_the_configuration_stays_polish(): void
    {
        $names = ['APP_LOCALE', 'APP_FALLBACK_LOCALE', 'APP_FAKER_LOCALE'];
        $saved = [$_ENV, $_SERVER, array_map(fn (string $name) => getenv($name), $names)];
        try {
            foreach ($names as $name) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            }
            $this->assertNull(env('APP_LOCALE'), 'Zmienne językowe usunięte ze środowiska.');

            $app = require base_path('config/app.php');
            $this->assertSame(['pl', 'pl', 'pl_PL'], [$app['locale'], $app['fallback_locale'], $app['faker_locale']]);
            $this->assertSame('pl', (require base_path('config/localization.php'))['default']);
        } finally {
            [$_ENV, $_SERVER] = $saved;
            foreach ($names as $index => $name) {
                $saved[2][$index] === false ? putenv($name) : putenv("{$name}={$saved[2][$index]}");
            }
        }
    }

    public function test_versioned_installation_and_test_configuration_sets_polish(): void
    {
        foreach (['.env.example', '.env.production.example'] as $file) {
            $contents = file_get_contents(base_path($file));
            $this->assertMatchesRegularExpression('/^APP_LOCALE=pl$/m', $contents, $file);
            $this->assertMatchesRegularExpression('/^APP_FALLBACK_LOCALE=pl$/m', $contents, $file);
        }
        $phpunit = file_get_contents(base_path('phpunit.xml'));
        $this->assertStringContainsString('<env name="APP_LOCALE" value="pl" force="true"/>', $phpunit);
        $this->assertStringContainsString('<env name="APP_FALLBACK_LOCALE" value="pl" force="true"/>', $phpunit);
    }

    public function test_access_denial_is_reported_in_polish_without_the_technical_reason(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_probe/denied', fn () => throw new AccessDenied('organization', 'X1'));
        Route::middleware('web')->get('/_probe/hidden', fn () => throw (new AccessDenied('organization', 'X2'))->hideAsNotFound());
        Route::middleware(['web', 'auth', 'mfa'])->get('/_probe/mfa', fn () => 'ok');

        $this->getJson('/_probe/denied')->assertForbidden()->assertExactJson(['message' => 'Nie masz uprawnień do wykonania tej czynności.']);
        $this->get('/_probe/denied')->assertForbidden()->assertSee('Nie masz uprawnień do wykonania tej czynności.')->assertDontSee('access.unauthorized');
        $this->getJson('/_probe/hidden')->assertNotFound()->assertExactJson(['message' => 'Nie znaleziono']);
        $this->get('/_probe/hidden')->assertNotFound()->assertSee('Nie znaleziono');
        $this->actingAs(User::factory()->create())->get('/_probe/mfa')->assertForbidden()->assertSee('Ta część wymaga włączonego uwierzytelniania dwuskładnikowego.');
        $this->getJson('/does-not-exist')->assertNotFound()->assertExactJson(['message' => 'Nie znaleziono strony.']);
    }

    public function test_validation_errors_are_in_polish_with_polish_field_names(): void
    {
        $this->post('/register', ['email' => 'not-an-email', 'password' => 'short'])
            ->assertSessionHasErrors([
                'given_name' => 'Pole imię jest wymagane.',
                'email' => 'Pole e-mail musi być prawidłowym adresem e-mail.',
            ]);

        $this->postJson('/register', [])->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Pole imię jest wymagane. (i jeszcze'));
    }

    public function test_missing_translation_falls_back_to_polish(): void
    {
        config(['localization.supported' => ['pl', 'en']]);
        App::setLocale('en');

        $this->assertSame('Moje dane', __('ui.nav.account'), 'Brak klucza w języku angielskim → wersja polska.');
        $this->assertSame('Nie masz uprawnień do wykonania tej czynności.', __('access.unauthorized'));
    }

    public function test_language_is_chosen_in_one_order_session_account_context_default(): void
    {
        config(['localization.supported' => ['pl', 'en', 'de']]);
        $account = User::factory()->create(['locale' => 'de']);
        $session = $this->app['session.store'];

        $this->assertSame('pl', $this->resolver()->resolve(null, null), 'Domyślnie polski.');
        $this->assertSame('en', $this->resolver()->resolve(null, null, 'en'), 'Język kontekstu (organizacja, strona publiczna).');
        $this->assertSame('de', $this->resolver()->resolve(null, $account, 'en'), 'Konto przed kontekstem.');
        $session->put('locale', 'en');
        $this->assertSame('en', $this->resolver()->resolve($session, $account), 'Jawny wybór w sesji przed kontem.');
        $session->put('locale', 'xx');
        $this->assertSame('de', $this->resolver()->resolve($session, $account), 'Nieobsługiwany język jest pomijany.');
        config(['localization.supported' => ['pl']]);
        $this->assertSame('pl', $this->resolver()->resolve($session, $account, 'en'), 'Wyłączony język wraca do polskiego.');
    }

    public function test_choice_is_kept_for_the_session_and_the_account(): void
    {
        $this->post('/locale', ['locale' => 'en'])->assertSessionHasErrors(['locale' => 'Wybrana wartość pola język jest nieprawidłowa.']);
        $this->post('/locale', ['locale' => 'pl'])->assertRedirect()->assertSessionHas('locale', 'pl');

        $account = User::factory()->create();
        $this->actingAs($account)->post('/locale', ['locale' => 'pl'])->assertSessionHas('locale', 'pl');
        $this->assertSame('pl', $account->fresh()->locale);
    }

    public function test_a_guest_choice_is_kept_in_the_session_and_used_on_public_pages(): void
    {
        config(['localization.supported' => ['pl', 'en']]);
        Lang::addLines(['auth.screens.login.title' => 'Sign in'], 'en');

        $this->post('/locale', ['locale' => 'en'])->assertRedirect()->assertSessionHas('locale', 'en');
        $this->assertGuest();
        $this->get('/login')->assertOk()->assertSee('<html lang="en">', false)->assertSee('Sign in')
            ->assertSee('Zapamiętaj mnie', false);
    }

    public function test_adding_a_language_needs_only_translations_and_configuration(): void
    {
        $this->post('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');

        config(['localization.supported' => ['pl', 'de']]);
        Lang::addLines(['ui.nav.account' => 'Meine Daten'], 'de');
        $account = User::factory()->create();
        $this->actingAs($account)->post('/locale', ['locale' => 'de'])->assertSessionHas('locale', 'de');

        $this->assertSame('de', $account->fresh()->locale, 'Wybór zapisany na koncie.');
        $this->assertSame('de', $this->resolver()->resolve(null, $account->fresh()), 'Konto pamięta wybór także bez sesji.');
        App::setLocale('de');
        $this->assertSame('Meine Daten', __('ui.nav.account'));
        $this->assertSame('Nie masz uprawnień do wykonania tej czynności.', __('access.unauthorized'), 'Brak tłumaczenia → polski.');
    }

    public function test_message_language_is_separate_from_the_interface_language(): void
    {
        config(['localization.supported' => ['pl', 'en']]);
        $account = User::factory()->create(['locale' => 'en']);
        $this->app['session.store']->put('locale', 'en');

        $this->assertSame('en', $this->resolver()->resolve($this->app['session.store'], $account), 'Interfejs po angielsku.');
        $this->assertSame('pl', $account->preferredLocale(), 'Wiadomości tylko w językach, dla których istnieją szablony.');

        config(['localization.message_languages' => ['pl', 'en'], 'localization.supported' => ['pl']]);
        $this->assertSame('en', $account->preferredLocale(), 'Język wiadomości nie zależy od listy języków interfejsu ani od sesji.');
        $this->assertSame('pl', $this->resolver()->resolve($this->app['session.store'], $account));
    }

    public function test_messages_to_an_account_use_its_language(): void
    {
        Notification::fake();
        config(['localization.supported' => ['pl', 'en'], 'localization.message_languages' => ['pl', 'en']]);
        $account = User::factory()->unverified()->create(['locale' => 'en']);

        $this->assertSame('en', $account->preferredLocale());
        $account->sendEmailVerificationNotification();
        Notification::assertSentTo($account, VerifyEmail::class, fn ($notification, $channels, $notifiable, $locale) => $locale === 'en');
        $this->assertSame('pl', User::factory()->create()->preferredLocale());
    }

    public function test_main_screens_show_no_untranslated_keys(): void
    {
        foreach (['/', '/login', '/register', '/forgot-password'] as $url) {
            $this->assertNoRawKeys($this->get($url)->assertOk()->getContent(), $url);
        }
        $person = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Anna', 'family_name' => 'Nowak']);
        $this->actingAs(User::factory()->create(['person_id' => $person->id]));
        foreach (['/account', '/account/contacts', '/account/security', '/account/represented'] as $url) {
            $this->assertNoRawKeys($this->get($url)->assertOk()->getContent(), $url);
        }
    }

    private function assertNoRawKeys(string $html, string $url): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(ui|account|auth|errors|access|identity|organization|permissions|validation|notifications)\.[a-z_]+(\.[a-z_]+)*\b/', strip_tags($html), "Nieprzetłumaczony klucz na {$url}");
    }
}
