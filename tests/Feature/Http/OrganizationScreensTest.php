<?php

namespace Tests\Feature\Http;

use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\CreateOrganizationUnit;
use App\Domain\Organization\Actions\FoundOrganization;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RunsAsSystem;
use Tests\TestCase;

/**
 * E3.10b: organization and structure screens — every path a user can take, its denials (403 / 404 for an
 * organization outside one's view) and the Polish texts.
 */
class OrganizationScreensTest extends TestCase
{
    use LazilyRefreshDatabase, RunsAsSystem;

    private function foundAs(User $founder, string $name = 'Fundacja Zielona'): Organization
    {
        $organization = $this->app->make(ActorContext::class)->runAs(Actor::account((string) $founder->id),
            fn () => $this->app->make(FoundOrganization::class)->handle($name, 'Założenie', (string) Str::ulid()));
        $this->travel(1)->second();

        return $organization;
    }

    private function unit(User $founder, Organization $parent, string $name): Organization
    {
        $unit = $this->app->make(ActorContext::class)->runAs(Actor::account((string) $founder->id),
            fn () => $this->app->make(CreateOrganizationUnit::class)->handle($parent, $name, 'Struktura'));
        $this->travel(1)->second();

        return $unit;
    }

    private function viewer(Organization $organization): User
    {
        $role = $this->asSystem(fn () => $this->app->make(CreateAccessRole::class)->handle($organization, 'Podgląd', ['organization.view'], 'Rola'));
        $viewer = User::factory()->create();
        $this->asSystem(fn () => $this->app->make(AssignRole::class)->handle($viewer, $role, $organization, ScopeInheritance::UnitAndDescendants, null, 'Nadanie'));
        $this->travel(1)->second();

        return $viewer;
    }

    public function test_screens_need_a_signed_in_account_with_a_verified_email(): void
    {
        $this->get('/organizations')->assertRedirect('/login');
        $this->post('/organizations', ['name' => 'X', 'request_key' => (string) Str::ulid()])->assertRedirect('/login');
        $this->actingAs(User::factory()->unverified()->create())->get('/organizations')->assertRedirect('/email/verify');
    }

    public function test_founding_through_the_form_explains_that_management_needs_mfa(): void
    {
        $founder = User::factory()->create();
        $this->actingAs($founder)->get('/organizations')->assertOk()->assertSee('Organizacje')->assertSee('Nie masz jeszcze dostępu do żadnej organizacji.');
        $form = $this->get('/organizations/new')->assertOk()->assertSee('Nowa organizacja')->assertSee('Nie daje to uprawnień do innych organizacji ani do całej platformy.');
        preg_match('/name="request_key" value="([^"]+)"/', $form->getContent(), $key);

        $this->post('/organizations', ['name' => 'Fundacja Zielona', 'request_key' => $key[1]])->assertRedirect('/organizations');
        $this->post('/organizations', ['name' => 'Fundacja Zielona', 'request_key' => $key[1]])->assertRedirect('/organizations');

        $this->assertSame(1, Organization::query()->count(), 'Ponowne wysłanie formularza nie zakłada drugiej organizacji.');
        $this->get('/organizations')->assertOk()
            ->assertSee('Organizacja „Fundacja Zielona” została założona.')
            ->assertSee('Organizacje czekające na włączenie uwierzytelniania dwuskładnikowego')
            ->assertSee('Organizacje pojawią się tutaj po włączeniu uwierzytelniania dwuskładnikowego.')
            ->assertDontSee('Nie masz jeszcze dostępu do żadnej organizacji.')
            ->assertSee(route('account.security'));
        $this->get(route('account.security'))->assertOk()->assertSee('Uwierzytelnianie dwuskładnikowe');
        $this->get('/organizations/'.Organization::query()->sole()->public_id)->assertNotFound();
    }

    public function test_founder_with_mfa_sees_the_organization_and_its_tree(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->foundAs($founder);
        $north = $this->unit($founder, $organization, 'Oddział Północ');
        $this->unit($founder, $north, 'Sekcja A');

        $this->actingAs($founder)->get('/organizations')->assertOk()->assertSee('Fundacja Zielona')->assertDontSee('Oddział Północ')
            ->assertDontSee('Organizacje czekające');
        $this->get('/organizations/'.$organization->public_id)->assertOk()
            ->assertSeeInOrder(['Fundacja Zielona', 'Oddział Północ', 'Sekcja A'])
            ->assertSee('Dodaj jednostkę podrzędną')->assertSee('Zmień nazwę')->assertSee('Archiwizuj')
            ->assertSee('Zarządzaj')->assertSee('aria-label="Zarządzaj: Oddział Północ"', false);
        $this->get('/organizations/'.$north->public_id)->assertOk()->assertSee('Położenie')->assertSee('Przenieś do');
    }

    public function test_foreign_organization_answers_not_found_and_the_denial_is_audited(): void
    {
        $foreign = $this->foundAs(User::factory()->withTwoFactor()->create(), 'Klub Obcy');
        $founder = User::factory()->withTwoFactor()->create();
        $this->foundAs($founder);

        $this->actingAs($founder)->get('/organizations')->assertDontSee('Klub Obcy');
        foreach ([
            fn () => $this->get('/organizations/'.$foreign->public_id),
            fn () => $this->post('/organizations/'.$foreign->public_id.'/units', ['name' => 'Wtyczka', 'reason' => 'Próba']),
            fn () => $this->put('/organizations/'.$foreign->public_id.'/name', ['name' => 'Przejęty', 'reason' => 'Próba']),
            fn () => $this->get('/organizations/'.$foreign->public_id.'/archive'),
            fn () => $this->post('/organizations/'.$foreign->public_id.'/archive', ['reason' => 'Próba', 'confirmation' => 'Klub Obcy']),
        ] as $attempt) {
            $attempt()->assertNotFound()->assertDontSee('Klub Obcy');
        }
        $this->assertSame('Klub Obcy', $foreign->fresh()->name);
        $this->assertGreaterThanOrEqual(5, AuditEntry::on('audit')->where('action', 'access.denied')->where('subject_id', $foreign->public_id)->count());
    }

    public function test_structure_changes_through_the_screens(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->foundAs($founder);
        $north = $this->unit($founder, $organization, 'Północ');
        $south = $this->unit($founder, $organization, 'Południe');
        $this->actingAs($founder);

        $this->from('/organizations/'.$organization->public_id)->post('/organizations/'.$north->public_id.'/units', ['name' => 'Sekcja', 'reason' => 'Nowa sekcja'])
            ->assertRedirect('/organizations/'.$organization->public_id)->assertSessionHas('status', 'Jednostka została utworzona.');
        $section = Organization::query()->where('name', 'Sekcja')->sole();
        $this->travel(1)->second();
        $this->put('/organizations/'.$section->public_id.'/name', ['name' => 'Sekcja Główna', 'reason' => 'Nazwa'])->assertSessionHas('status', 'Nazwa została zmieniona.');
        $this->travel(1)->second();
        $this->put('/organizations/'.$section->public_id.'/parent', ['parent' => $south->public_id, 'reason' => 'Reorganizacja'])->assertSessionHas('status', 'Jednostka została przeniesiona.');
        $this->assertSame($south->id, OrganizationParent::query()->where('organization_id', $section->id)->whereNull('valid_to')->sole()->parent_id);

        $this->travel(1)->second();
        $this->get('/organizations/'.$section->public_id.'/archive')->assertOk()->assertSee('Archiwizujesz jednostkę.')->assertDontSee('wpisz dokładnie nazwę');
        $this->post('/organizations/'.$section->public_id.'/archive', ['reason' => 'Likwidacja'])
            ->assertRedirect('/organizations/'.$south->public_id)->assertSessionHas('status', '„Sekcja Główna” została zarchiwizowana.');
        $this->assertSame(OrganizationStatus::Archived, $section->fresh()->status);
    }

    public function test_archiving_a_whole_organization_warns_and_needs_the_name_typed_again(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->foundAs($founder);
        $this->actingAs($founder);

        $this->get('/organizations/'.$organization->public_id.'/archive')->assertOk()
            ->assertSee('Archiwizujesz całą organizację razem ze wszystkimi jednostkami.')
            ->assertSee('Aby potwierdzić, wpisz dokładnie nazwę organizacji: Fundacja Zielona');
        $this->post('/organizations/'.$organization->public_id.'/archive', ['reason' => 'Likwidacja', 'confirmation' => 'Fundacja'])
            ->assertSessionHasErrors(['confirmation' => 'Wpisana nazwa nie zgadza się z nazwą organizacji.']);
        $this->post('/organizations/'.$organization->public_id.'/archive', ['reason' => '', 'confirmation' => 'Fundacja Zielona'])->assertSessionHasErrors('reason');
        $this->assertSame(OrganizationStatus::Active, $organization->fresh()->status);

        $this->post('/organizations/'.$organization->public_id.'/archive', ['reason' => 'Likwidacja', 'confirmation' => 'Fundacja Zielona'])
            ->assertRedirect('/organizations')->assertSessionHas('status', '„Fundacja Zielona” została zarchiwizowana.');
        $this->assertSame(OrganizationStatus::Archived, $organization->fresh()->status);
    }

    public function test_viewer_sees_the_structure_without_forms_and_is_refused_changes(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->foundAs($founder);
        $unit = $this->unit($founder, $organization, 'Północ');
        $viewer = $this->viewer($organization);
        config(['app.debug' => false]);

        $this->actingAs($viewer)->get('/organizations/'.$organization->public_id)->assertOk()->assertSee('Północ')
            ->assertDontSee('Dodaj jednostkę podrzędną')->assertDontSee('Zmień nazwę')->assertDontSee('Archiwizuj');
        $this->from('/organizations/'.$organization->public_id)->post('/organizations/'.$organization->public_id.'/units', ['name' => 'Wtyczka', 'reason' => 'Próba'])
            ->assertForbidden()->assertSee('Nie masz uprawnień do wykonania tej czynności.')
            ->assertSee('Wróć do poprzedniej strony')->assertSee(route('organizations.show', $organization->public_id), false);
        $this->put('/organizations/'.$unit->public_id.'/name', ['name' => 'Przejęta', 'reason' => 'Próba'])->assertForbidden();
        $this->post('/organizations/'.$organization->public_id.'/archive', ['reason' => 'Próba', 'confirmation' => 'Fundacja Zielona'])->assertForbidden();
        $this->assertSame(2, Organization::query()->count());
    }

    public function test_founding_attempts_are_limited_with_a_polish_message(): void
    {
        config(['organization.founding.attempts_per_hour' => 2, 'app.debug' => false]);
        $founder = User::factory()->create();
        $this->actingAs($founder);

        foreach (['Pierwsza', 'Druga'] as $name) {
            $this->post('/organizations', ['name' => $name, 'request_key' => (string) Str::ulid()])->assertRedirect('/organizations');
        }
        $this->post('/organizations', ['name' => 'Trzecia', 'request_key' => (string) Str::ulid()])
            ->assertStatus(429)->assertSee('Zbyt wiele prób założenia organizacji. Spróbuj ponownie później.');
        $this->assertSame(2, Organization::query()->count());
    }

    public function test_validation_messages_are_polish(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $organization = $this->foundAs($founder);

        $this->actingAs($founder)->post('/organizations', ['name' => '', 'request_key' => (string) Str::ulid()])->assertSessionHasErrors(['name' => 'Pole nazwa jest wymagane.']);
        $this->post('/organizations/'.$organization->public_id.'/units', ['name' => 'Sekcja', 'reason' => ''])->assertSessionHasErrors(['reason' => 'Pole powód jest wymagane.']);
    }

    public function test_password_hints_show_the_current_minimum(): void
    {
        $this->get('/register')->assertSee('Hasło (co najmniej 15 znaków — może być zdanie ze spacjami)');
        $this->actingAs(User::factory()->create())->get(route('account.security'))->assertSee('Nowe hasło (co najmniej 15 znaków — może być zdanie ze spacjami)')->assertDontSee('12 znaków');
    }
}
