<?php

namespace Tests\Feature\Http\Account;

use App\Domain\Identity\Actions\AddContact;
use App\Domain\Identity\Actions\GrantRepresentation;
use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope as Scope;
use App\Domain\Identity\Models\Contact;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Notifications\ContactVerificationCode;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountScreensTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function person(string $given = 'Anna'): Person
    {
        return $this->app->make(RegisterPerson::class)->handle(['given_name' => $given, 'family_name' => 'Nowak', 'birth_date' => '1990-05-01']);
    }

    private function holder(?Person $person = null): User
    {
        return User::factory()->create(['person_id' => ($person ?? $this->person())->id, 'password' => self::PASSWORD]);
    }

    private function deniedCount(): int
    {
        return AuditEntry::on('audit')->where('action', 'access.denied')->count();
    }

    public function test_screens_require_a_verified_login(): void
    {
        foreach (['/account', '/account/contacts', '/account/security', '/account/represented'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->unverified()->create())->get('/account')->assertRedirect('/email/verify');
    }

    public function test_all_screens_render_for_the_account_holder(): void
    {
        $this->actingAs($this->holder());

        $this->get('/account')->assertOk()->assertSee('Moje dane')->assertSee('Nowak');
        $this->get('/account/contacts')->assertOk()->assertSee('Brak kontaktów');
        $this->get('/account/security')->assertOk()->assertSee('Uwierzytelnianie dwuskładnikowe')->assertSee('Wyłączone');
        $this->get('/account/represented')->assertOk()->assertSee('Nie reprezentujesz żadnej osoby');
    }

    public function test_unlinked_account_sees_an_explanation(): void
    {
        $this->actingAs(User::factory()->create())->get('/account')->assertOk()->assertSee('nie jest jeszcze połączone');
        $this->get('/account/contacts')->assertNotFound();
    }

    public function test_holder_corrects_own_data_with_validation_and_audit(): void
    {
        $person = $this->person();
        $this->actingAs($this->holder($person));

        $this->put('/account', ['given_name' => '', 'family_name' => 'Nowak'])->assertSessionHasErrors('given_name');
        $this->put('/account', ['given_name' => 'Anna', 'family_name' => 'Kowalska', 'birth_date' => '1990-05-01'])->assertRedirect('/account');

        $this->assertSame('Kowalska', $person->fresh()->family_name);
        $this->assertSame('account holder updated own data', AuditEntry::query()->where('action', 'person.updated')->sole()->reason);
    }

    public function test_contacts_can_be_added_verified_and_removed(): void
    {
        Notification::fake();
        $person = $this->person();
        $this->actingAs($this->holder($person));

        $this->post('/account/contacts', ['channel' => 'email', 'value' => 'Anna@Example.test'])->assertRedirect();
        $contact = Contact::query()->sole();
        $this->get('/account/contacts')->assertSee('anna@example.test')->assertSee('niepotwierdzony');

        $this->post("/account/contacts/{$contact->public_id}/verification")->assertRedirect();
        $code = null;
        Notification::assertSentTo(new AnonymousNotifiable, ContactVerificationCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->post("/account/contacts/{$contact->public_id}/verify", ['code' => 'nope'])->assertSessionHasErrors('code');
        $this->post("/account/contacts/{$contact->public_id}/verify", ['code' => $code])->assertSessionHasNoErrors();
        $this->assertTrue($contact->fresh()->isVerified());

        $this->delete("/account/contacts/{$contact->public_id}")->assertRedirect();
        $this->assertNotNull($contact->fresh()->removed_at);
        $this->get('/account/contacts')->assertSee('Brak kontaktów');
    }

    public function test_someone_elses_contact_does_not_exist_for_the_holder(): void
    {
        $foreign = $this->app->make(AddContact::class)->handle($this->person('Obca'), ContactChannel::Email, 'other@example.test');
        $this->actingAs($this->holder());

        $this->delete("/account/contacts/{$foreign->public_id}")->assertNotFound();
        $this->post("/account/contacts/{$foreign->public_id}/verification")->assertNotFound();

        $this->assertNull($foreign->fresh()->removed_at);
        $this->assertSame(2, $this->deniedCount());
    }

    public function test_password_change_from_the_security_screen(): void
    {
        $account = $this->holder();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $account->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($account);

        $this->from('/account/security')->put('/user/password', ['current_password' => 'wrong-password-1', 'password' => 'new-password-4567', 'password_confirmation' => 'new-password-4567'])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');
        $this->from('/account/security')->put('/user/password', ['current_password' => self::PASSWORD, 'password' => 'new-password-4567', 'password_confirmation' => 'new-password-4567'])
            ->assertRedirect('/account/security');

        $this->assertTrue(Hash::check('new-password-4567', $account->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device')->count());
    }

    public function test_representative_sees_and_edits_the_represented_person_within_scope(): void
    {
        $parent = $this->person('Maria');
        $child = $this->person('Zosia');
        $this->app->make(GrantRepresentation::class)->handle($parent, $child, [Scope::ProfileView, Scope::ProfileUpdate], RepresentationMethod::Document, 'birth certificate no. AB-123 checked', now()->subDay(), 'operator');
        $this->actingAs($this->holder($parent));

        $this->get('/account/represented')->assertOk()->assertSee('Zosia Nowak')->assertSee('dokument');
        $this->get("/account/represented/{$child->public_id}")->assertOk()->assertSee('Zapisz');
        $this->put("/account/represented/{$child->public_id}", ['given_name' => 'Zofia', 'family_name' => 'Nowak'])->assertRedirect();

        $this->assertSame('Zofia', $child->fresh()->given_name);
        $this->assertSame(1, AuditEntry::query()->where('action', 'person.acted_on_behalf')->count());
    }

    public function test_view_only_representation_cannot_edit(): void
    {
        $parent = $this->person('Maria');
        $child = $this->person('Zosia');
        $this->app->make(GrantRepresentation::class)->handle($parent, $child, [Scope::ProfileView], RepresentationMethod::Document, 'birth certificate no. AB-123 checked', now()->subDay(), 'operator');
        $this->actingAs($this->holder($parent));

        $this->get("/account/represented/{$child->public_id}")->assertOk()->assertDontSee('Zapisz');
        $this->put("/account/represented/{$child->public_id}", ['given_name' => 'Zofia', 'family_name' => 'Nowak'])->assertNotFound();

        $this->assertSame('Zosia', $child->fresh()->given_name);
        $this->assertSame(1, $this->deniedCount());
    }

    public function test_stranger_cannot_see_a_person_and_the_attempt_is_audited(): void
    {
        $stranger = $this->person('Obca');
        $this->actingAs($this->holder());

        $this->get("/account/represented/{$stranger->public_id}")->assertNotFound()->assertDontSee('Obca');

        $denial = AuditEntry::on('audit')->where('action', 'access.denied')->sole();
        $this->assertSame(['person', $stranger->public_id], [$denial->subject_type, $denial->subject_id]);
    }

    public function test_phone_screen_never_claims_that_a_code_was_sent(): void
    {
        Notification::fake();
        $this->actingAs($this->holder());
        $this->post('/account/contacts', ['channel' => 'phone', 'value' => '600100200']);
        $phone = Contact::query()->sole();

        $this->get('/account/contacts')->assertSee('potwierdzanie tego kanału nie jest jeszcze dostępne')->assertDontSee('wyślij kod');
        $this->post("/account/contacts/{$phone->public_id}/verification")->assertSessionHasErrors('contact')->assertSessionMissing('status');

        $this->assertFalse($phone->fresh()->isVerified());
        Notification::assertNothingSent();
    }
}
