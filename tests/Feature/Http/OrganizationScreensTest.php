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
 * Organization and structure screens (E3.10b, pattern E3.10f): "Zarządzaj" lists the allowed actions, each opens its
 * own screen with "Anuluj"; moving asks for confirmation; archiving covers sub-units; archived units and their
 * history need today's history right. Every path, its denials (403 / 404 outside one's view) and the Polish texts.
 */
class OrganizationScreensTest extends TestCase
{
    use LazilyRefreshDatabase, RunsAsSystem;

    private function foundAs(User $founder, string $name = 'KKP'): Organization
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

    /** @param list<string> $permissions */
    private function holder(Organization $organization, array $permissions): User
    {
        $role = $this->asSystem(fn () => $this->app->make(CreateAccessRole::class)->handle($organization, 'Rola '.Str::random(5), $permissions, 'Rola'));
        $account = User::factory()->create();
        $this->asSystem(fn () => $this->app->make(AssignRole::class)->handle($account, $role, $organization, ScopeInheritance::UnitAndDescendants, null, 'Nadanie'));
        $this->travel(1)->second();

        return $account;
    }

    /** @return array{0: User, 1: Organization, 2: Organization, 3: Organization, 4: Organization} founder, KKP, OKR 1, OKR 3, Koło Wołów (in OKR 1) */
    private function structure(): array
    {
        $founder = User::factory()->withTwoFactor()->create();
        $kkp = $this->foundAs($founder);
        $okr1 = $this->unit($founder, $kkp, 'OKR 1');
        $okr3 = $this->unit($founder, $kkp, 'OKR 3');
        $circle = $this->unit($founder, $okr1, 'Koło Wołów');

        return [$founder, $kkp, $okr1, $okr3, $circle];
    }

    public function test_screens_need_a_signed_in_account_with_a_verified_email(): void
    {
        $this->get('/organizations')->assertRedirect('/login');
        $this->post('/organizations', ['name' => 'X', 'request_key' => (string) Str::ulid()])->assertRedirect('/login');
        $this->actingAs(User::factory()->unverified()->create())->get('/organizations')->assertRedirect('/email/verify');
    }

    public function test_founding_without_mfa_shows_one_notice_with_the_way_to_enable_it(): void
    {
        $founder = User::factory()->create();
        $this->actingAs($founder)->get('/organizations')->assertOk()->assertSee('Nie masz jeszcze dostępu do żadnej organizacji.');
        $form = $this->get('/organizations/new')->assertOk()->assertSee('Nowa organizacja')->assertSee('Anuluj')->assertSee('href="'.route('organizations.index').'"', false);
        preg_match('/name="request_key" value="([^"]+)"/', $form->getContent(), $key);

        $this->post('/organizations', ['name' => 'KKP', 'request_key' => $key[1]])->assertRedirect('/organizations');
        $this->post('/organizations', ['name' => 'KKP', 'request_key' => $key[1]])->assertRedirect('/organizations');

        $this->assertSame(1, Organization::query()->count(), 'Ponowne wysłanie formularza nie zakłada drugiej organizacji.');
        $page = $this->get('/organizations')->assertOk()
            ->assertSee('Organizacja »KKP« została założona.')
            ->assertSee('Włącz weryfikację dwuetapową, aby zarządzać organizacją')
            ->assertSee(route('account.security'))
            ->assertDontSee('Nie masz jeszcze dostępu do żadnej organizacji.')
            ->getContent();
        $this->assertSame(1, substr_count($page, 'role="alert"'), 'Jeden komunikat zamiast kilku powtarzających się.');
    }

    public function test_founding_with_mfa_opens_the_new_organization(): void
    {
        $founder = User::factory()->withTwoFactor()->create();

        $this->actingAs($founder)->post('/organizations', ['name' => 'KKP', 'request_key' => (string) Str::ulid()])
            ->assertRedirect(route('organizations.show', Organization::query()->sole()->public_id))
            ->assertSessionHas('status', 'Organizacja »KKP« została założona.');
    }

    public function test_unit_screen_offers_manage_actions_then_sub_units(): void
    {
        [$founder, $kkp, $okr1] = $this->structure();

        $this->actingAs($founder)->get('/organizations')->assertSee('aria-label="Otwórz organizację KKP"', false)->assertSee('aria-current="page"', false);
        $this->get('/organizations/'.$kkp->public_id)->assertOk()
            ->assertSeeInOrder(['Zarządzaj', 'Dodaj jednostkę podrzędną', 'Zmień nazwę', 'Archiwizuj', 'Jednostki podrzędne', 'OKR 1', 'Koło Wołów', 'OKR 3', 'Zarchiwizowane jednostki'])
            ->assertDontSee(route('organizations.move.choose', $kkp->public_id));
        $this->get('/organizations/'.$okr1->public_id)->assertOk()
            ->assertSee('Położenie')->assertSee(route('organizations.show', $kkp->public_id))
            ->assertSeeInOrder(['Dodaj jednostkę podrzędną', 'Zmień nazwę', 'Przenieś', 'Archiwizuj']);
    }

    public function test_creating_and_renaming_need_no_reason_and_record_it_automatically(): void
    {
        [$founder, $kkp] = $this->structure();
        $this->actingAs($founder);

        $this->get('/organizations/'.$kkp->public_id.'/units/new')->assertOk()
            ->assertSee('Nazwa jednostki podrzędnej')->assertSee('Utwórz')->assertSee('Anuluj')->assertDontSee('Powód');
        $this->post('/organizations/'.$kkp->public_id.'/units', ['name' => 'OKR 2'])
            ->assertRedirect(route('organizations.show', $kkp->public_id))->assertSessionHas('status', 'Jednostka »OKR 2« została utworzona.');
        $created = Organization::query()->where('name', 'OKR 2')->sole();
        $this->assertSame('unit created on the organization screen', AuditEntry::query()->where(['action' => 'organization.created', 'subject_id' => (string) $created->id])->sole()->reason);
        $this->travel(1)->second();

        $this->get('/organizations/'.$created->public_id.'/name')->assertOk()->assertSee('Nowa nazwa')->assertSee('value="OKR 2"', false)->assertDontSee('Powód');
        $this->put('/organizations/'.$created->public_id.'/name', ['name' => 'Okręg 2'])->assertSessionHas('status', 'Nazwa została zmieniona na »Okręg 2«.');
        $this->assertSame('renamed on the organization screen', AuditEntry::query()->where(['action' => 'organization.updated', 'subject_id' => (string) $created->id])->sole()->reason);
        $this->post('/organizations/'.$kkp->public_id.'/units', ['name' => ''])->assertSessionHasErrors(['name' => 'Podaj nazwę jednostki podrzędnej.']);
    }

    public function test_moving_directly_between_sibling_units_asks_for_confirmation(): void
    {
        [$founder, , $okr1, $okr3, $circle] = $this->structure();
        $this->unit($founder, $circle, 'WOK');
        $this->actingAs($founder);

        $this->get('/organizations/'.$circle->public_id.'/move')->assertOk()
            ->assertSee('KKP › OKR 1')->assertSee('KKP › OKR 3')->assertSee('Dalej')->assertSee('Anuluj');
        $this->get('/organizations/'.$circle->public_id.'/move/confirm')->assertSessionHasErrors(['parent' => 'Wybierz miejsce, do którego chcesz przenieść jednostkę.']);
        $this->get('/organizations/'.$circle->public_id.'/move/confirm?parent='.$okr3->public_id)->assertOk()
            ->assertSeeInOrder(['Przenosisz', 'Koło Wołów', 'Obecne położenie', 'KKP › OKR 1', 'Nowe położenie', 'KKP › OKR 3'])
            ->assertSee('Razem z nią zostaną przeniesione jednostki podrzędne (1):')->assertSee('WOK')
            ->assertSee('Dostęp')->assertSee('Anuluj')->assertSee('Przenieś');
        $this->assertSame($okr1->id, OrganizationParent::query()->where('organization_id', $circle->id)->whereNull('valid_to')->sole()->parent_id, 'Ekran potwierdzenia niczego nie zmienia.');

        $this->put('/organizations/'.$circle->public_id.'/parent', ['parent' => $okr3->public_id])
            ->assertSessionHas('status', 'Jednostka »Koło Wołów« została przeniesiona do »OKR 3«.');
        $this->assertSame($okr3->id, OrganizationParent::query()->where('organization_id', $circle->id)->whereNull('valid_to')->sole()->parent_id, 'Bezpośrednio z OKR 1 do OKR 3.');
        $this->assertSame(1, AuditEntry::query()->where(['action' => 'organization_parent.created', 'reason' => 'moved on the organization screen'])->count());
    }

    public function test_archiving_explains_and_covers_the_sub_units(): void
    {
        [$founder, , $okr1, , $circle] = $this->structure();
        $wok = $this->unit($founder, $circle, 'WOK');
        $this->actingAs($founder);

        $this->get('/organizations/'.$circle->public_id.'/archive')->assertOk()
            ->assertSee('Zostaną zarchiwizowane także jednostki podrzędne (1):')->assertSee('WOK')
            ->assertSee('Powód archiwizacji')->assertSee('Nic nie zostanie usunięte')->assertDontSee('wpisz dokładnie nazwę');
        $this->post('/organizations/'.$circle->public_id.'/archive', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post('/organizations/'.$circle->public_id.'/archive', ['reason' => 'Likwidacja koła'])
            ->assertRedirect(route('organizations.show', $okr1->public_id))->assertSessionHas('status', 'Jednostka »Koło Wołów« została zarchiwizowana.');

        $this->assertSame([OrganizationStatus::Archived, OrganizationStatus::Archived], [$circle->fresh()->status, $wok->fresh()->status]);
        $this->get('/organizations/'.$okr1->public_id)
            ->assertDontSee(route('organizations.show', $circle->public_id))->assertDontSee(route('organizations.show', $wok->public_id));
    }

    public function test_archiving_a_whole_organization_needs_its_name_typed_again(): void
    {
        [$founder, $kkp] = $this->structure();
        $this->actingAs($founder);

        $this->get('/organizations/'.$kkp->public_id.'/archive')->assertOk()
            ->assertSee('Archiwizujesz całą organizację.')->assertSee('Zostaną zarchiwizowane także jednostki podrzędne (3):')
            ->assertSee('Aby potwierdzić, wpisz dokładnie nazwę organizacji: KKP');
        $this->post('/organizations/'.$kkp->public_id.'/archive', ['reason' => 'Likwidacja', 'confirmation' => 'kkp'])
            ->assertSessionHasErrors(['confirmation' => 'Wpisana nazwa nie zgadza się z nazwą organizacji.']);
        $this->post('/organizations/'.$kkp->public_id.'/archive', ['reason' => 'Likwidacja', 'confirmation' => 'KKP'])
            ->assertRedirect('/organizations')->assertSessionHas('status', 'Organizacja »KKP« została zarchiwizowana.');
        $this->assertSame(4, Organization::query()->where('status', OrganizationStatus::Archived)->count());
    }

    public function test_archived_units_and_their_history_need_todays_history_right(): void
    {
        [$founder, $kkp, , $okr3, $circle] = $this->structure();
        $viewer = $this->holder($kkp, ['organization.view']);
        $this->actingAs($founder)->put('/organizations/'.$circle->public_id.'/parent', ['parent' => $okr3->public_id]);
        $this->travel(1)->second();
        $this->post('/organizations/'.$circle->public_id.'/archive', ['reason' => 'Likwidacja koła']);
        $archivedOn = now()->setTimezone('Europe/Warsaw')->format('d.m.Y');
        $this->travel(1)->second();

        $this->get('/organizations/'.$kkp->public_id.'/archived')->assertOk()
            ->assertSee('Zarchiwizowane jednostki')->assertSee('Koło Wołów')->assertSee('zarchiwizowana '.$archivedOn);
        $this->get('/organizations/archived/'.$circle->public_id)->assertOk()
            ->assertSee('Historia: Koło Wołów')->assertSee('KKP › OKR 3')
            ->assertSeeInOrder(['Położenie w czasie', 'OKR 1', 'OKR 3']);

        $this->flushSession();
        $this->actingAs($viewer)->get('/organizations/'.$kkp->public_id)->assertOk()->assertDontSee('Zarchiwizowane jednostki');
        $this->get('/organizations/'.$kkp->public_id.'/archived')->assertForbidden();
        $this->get('/organizations/archived/'.$circle->public_id)->assertForbidden();
    }

    public function test_viewer_sees_the_structure_without_actions_and_is_refused_changes(): void
    {
        [, $kkp, $okr1] = $this->structure();
        $viewer = $this->holder($kkp, ['organization.view']);
        config(['app.debug' => false]);

        $this->actingAs($viewer)->get('/organizations/'.$kkp->public_id)->assertOk()->assertSee('OKR 1')
            ->assertSee('Możesz przeglądać tę jednostkę, ale nie masz uprawnień do zmian.')
            ->assertDontSee('Dodaj jednostkę podrzędną')->assertDontSee('Archiwizuj');
        $this->from('/organizations/'.$kkp->public_id)->post('/organizations/'.$kkp->public_id.'/units', ['name' => 'Wtyczka'])
            ->assertForbidden()->assertSee('Nie masz uprawnień do wykonania tej czynności.')->assertSee('Wróć do poprzedniej strony');
        $this->put('/organizations/'.$okr1->public_id.'/name', ['name' => 'Przejęta'])->assertForbidden();
        $this->post('/organizations/'.$kkp->public_id.'/archive', ['reason' => 'Próba', 'confirmation' => 'KKP'])->assertForbidden();
        $this->assertSame(4, Organization::query()->where('status', OrganizationStatus::Active)->count());
    }

    public function test_foreign_organization_answers_not_found_and_the_denial_is_audited(): void
    {
        $foreign = $this->foundAs(User::factory()->withTwoFactor()->create(), 'Klub Obcy');
        $founder = User::factory()->withTwoFactor()->create();
        $this->foundAs($founder);

        $this->actingAs($founder)->get('/organizations')->assertDontSee('Klub Obcy');
        foreach ([
            fn () => $this->get('/organizations/'.$foreign->public_id),
            fn () => $this->get('/organizations/'.$foreign->public_id.'/units/new'),
            fn () => $this->post('/organizations/'.$foreign->public_id.'/units', ['name' => 'Wtyczka']),
            fn () => $this->put('/organizations/'.$foreign->public_id.'/name', ['name' => 'Przejęty']),
            fn () => $this->get('/organizations/'.$foreign->public_id.'/archive'),
            fn () => $this->post('/organizations/'.$foreign->public_id.'/archive', ['reason' => 'Próba', 'confirmation' => 'Klub Obcy']),
        ] as $attempt) {
            $attempt()->assertNotFound()->assertDontSee('Klub Obcy');
        }
        $this->assertSame('Klub Obcy', $foreign->fresh()->name);
        $this->assertGreaterThanOrEqual(6, AuditEntry::on('audit')->where('action', 'access.denied')->where('subject_id', $foreign->public_id)->count());
    }

    public function test_founding_attempts_are_limited_with_a_polish_message(): void
    {
        config(['organization.founding.attempts_per_hour' => 2, 'app.debug' => false]);
        $this->actingAs(User::factory()->create());

        foreach (['Pierwsza', 'Druga'] as $name) {
            $this->post('/organizations', ['name' => $name, 'request_key' => (string) Str::ulid()])->assertRedirect('/organizations');
        }
        $this->post('/organizations', ['name' => 'Trzecia', 'request_key' => (string) Str::ulid()])
            ->assertStatus(429)->assertSee('Zbyt wiele prób założenia organizacji. Spróbuj ponownie później.');
        $this->assertSame(2, Organization::query()->count());
    }

    public function test_password_hints_show_the_current_minimum(): void
    {
        $this->get('/register')->assertSee('Hasło (co najmniej 15 znaków — może być zdanie ze spacjami)');
        $this->actingAs(User::factory()->create())->get(route('account.security'))->assertSee('Nowe hasło (co najmniej 15 znaków — może być zdanie ze spacjami)')->assertDontSee('12 znaków');
    }
}
