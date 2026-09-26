<?php

namespace Tests\Feature\Domain\Identity;

use App\Domain\Identity\Actions\LinkAccountToPerson;
use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Actions\UpdatePersonDetails;
use App\Domain\Identity\Exceptions\AccountLinkConflict;
use App\Domain\Identity\Models\Person;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function registration(array $overrides = []): array
    {
        return [...[
            'given_name' => 'Anna', 'family_name' => 'Nowak', 'email' => 'Anna@Example.TEST',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ], ...$overrides];
    }

    private function person(): Person
    {
        return $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Anna', 'family_name' => 'Nowak']);
    }

    private function link(User $account, Person $person): User
    {
        return $this->app->make(LinkAccountToPerson::class)->handle($account, $person, 'verified e-mail matches person contact');
    }

    public function test_screens_render(): void
    {
        $this->get('/login')->assertOk()->assertSee('Logowanie');
        $this->get('/register')->assertOk()->assertSee('Zakładanie konta');
    }

    public function test_registration_creates_an_account_that_is_not_yet_a_person(): void
    {
        $this->post('/register', $this->registration())->assertRedirect('/account');

        $account = User::query()->sole();
        $this->assertAuthenticatedAs($account);
        $this->assertSame('anna@example.test', $account->email);
        $this->assertNull($account->person_id, 'Konto dołącza do PERSON dopiero po weryfikacji e-maila (E2.4).');
        $this->assertSame(0, Person::query()->count());
        $this->assertSame('anonymous', AuditEntry::query()->where('action', 'account.created')->sole()->actor_type->value);
    }

    public function test_registration_is_refused_for_invalid_data(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->post('/register', $this->registration(['email' => 'TAKEN@example.test']))->assertSessionHasErrors('email');
        $this->post('/register', $this->registration(['password' => 'short-pass1', 'password_confirmation' => 'short-pass1']))->assertSessionHasErrors('password');
        $this->post('/register', $this->registration(['password_confirmation' => 'something-else-123']))->assertSessionHasErrors('password');
        $this->post('/register', $this->registration(['given_name' => '  ']))->assertSessionHasErrors('given_name');

        $this->assertSame(1, User::query()->count());
        $this->assertGuest();
    }

    public function test_login_and_logout(): void
    {
        $account = User::factory()->create(['email' => 'anna@example.test', 'password' => self::PASSWORD]);

        $this->post('/login', ['email' => 'ANNA@example.test', 'password' => 'wrong-password-123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'ANNA@example.test', 'password' => self::PASSWORD])->assertRedirect('/account');
        $this->assertAuthenticatedAs($account);

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $rotation = AuditEntry::query()->where('action', 'account.updated')->sole();
        $this->assertSame('session token rotated', $rotation->reason);
        $this->assertSame(['remember_token' => '[REDACTED]'], $rotation->after_values);
    }

    public function test_account_links_to_an_existing_person_without_changing_its_identity_or_history(): void
    {
        $person = $this->person();
        $this->app->make(UpdatePersonDetails::class)->handle($person, ['family_name' => 'Kowalska'], 'name change');
        $history = AuditEntry::query()->where('subject_type', 'person')->pluck('id')->all();
        $account = User::factory()->create();

        $this->link($account, $person);

        $this->assertTrue($account->fresh()->person->is($person));
        $this->assertSame($person->public_id, $person->fresh()->public_id);
        $this->assertSame($history, AuditEntry::query()->where('subject_type', 'person')->pluck('id')->all(), 'Historia PERSON nie zmienia się.');
        $entry = AuditEntry::query()->where('action', 'account.updated')->sole();
        $this->assertSame(['person_id' => $person->id], $entry->after_values);
        $this->assertSame('verified e-mail matches person contact', $entry->reason);
    }

    public function test_a_person_has_at_most_one_account(): void
    {
        $person = $this->person();
        $this->link(User::factory()->create(), $person);

        $this->expectException(AccountLinkConflict::class);
        $this->link(User::factory()->create(), $person);
    }

    public function test_linking_again_to_the_same_person_is_harmless(): void
    {
        $person = $this->person();
        $account = $this->link(User::factory()->create(), $person);

        $this->link($account, $person);

        $this->assertSame(1, AuditEntry::query()->where('action', 'account.updated')->count());
    }

    public function test_a_linked_account_never_moves_to_another_person(): void
    {
        $account = $this->link(User::factory()->create(), $this->person());

        try {
            $this->link($account, $this->person());
            $this->fail('Linked account must not move.');
        } catch (AccountLinkConflict) {
        }

        $this->expectException(LogicException::class);
        $this->app->make(AuditReason::class)->because('direct attempt', fn () => $account->forceFill(['person_id' => $this->person()->id])->save());
    }

    public function test_database_refuses_a_second_account_for_a_person_even_without_the_action(): void
    {
        $person = $this->person();
        User::factory()->create(['person_id' => $person->id]);

        $this->expectException(QueryException::class);
        User::factory()->create(['person_id' => $person->id]);
    }
}
