<?php

namespace Tests\Feature\Domain\Identity;

use App\Domain\Identity\Actions\AddContact;
use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Actions\ResolveAccountPerson;
use App\Domain\Identity\Actions\ResolvePersonLinkReview;
use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Enums\PersonLinkReviewStatus;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\PersonLinkReview;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AccountPersonLinkTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function personWithEmail(string $email, bool $verified = true, string $given = 'Anna'): Person
    {
        $person = $this->app->make(RegisterPerson::class)->handle(['given_name' => $given, 'family_name' => 'Nowak']);
        $contact = $this->app->make(AddContact::class)->handle($person, ContactChannel::Email, $email);
        if ($verified) {
            $this->app->make(AuditReason::class)->because('verified in test', fn () => $contact->update(['verified_at' => now()]));
        }

        return $person;
    }

    private function verifyThroughLink(User $account): TestResponse
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $account->id, 'hash' => sha1($account->email)]);

        return $this->actingAs($account)->get($url);
    }

    public function test_registration_sends_a_verification_link_and_shows_the_notice(): void
    {
        Notification::fake();

        $this->post('/register', ['given_name' => 'Anna', 'family_name' => 'Nowak', 'email' => 'anna@example.test',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery']);

        $account = User::query()->sole();
        Notification::assertSentTo($account, VerifyEmail::class);
        $this->get('/email/verify')->assertOk()->assertSee('anna@example.test');
    }

    public function test_verified_account_without_matching_person_gets_a_new_person_with_verified_contact(): void
    {
        $account = User::factory()->unverified()->create(['given_name' => 'Anna', 'family_name' => 'Nowak', 'email' => 'anna@example.test']);

        $this->verifyThroughLink($account)->assertRedirect();

        $account->refresh();
        $this->assertTrue($account->hasVerifiedEmail());
        $person = $account->person;
        $this->assertSame(['Anna', 'Nowak'], [$person->given_name, $person->family_name]);
        $this->assertTrue($person->contacts()->active()->verified()->where('value', 'anna@example.test')->exists());
        $this->assertSame('account e-mail verified by signed link', AuditEntry::query()->where('action', 'account.updated')->orderBy('id')->first()->reason);
    }

    public function test_later_account_joins_the_existing_person_and_keeps_its_history(): void
    {
        $person = $this->personWithEmail('anna@example.test');
        $history = AuditEntry::query()->where('subject_type', 'person')->where('subject_id', (string) $person->id)->pluck('id')->all();
        $account = User::factory()->unverified()->create(['email' => 'anna@example.test']);

        $this->verifyThroughLink($account);

        $this->assertTrue($account->fresh()->person->is($person));
        $this->assertSame(1, Person::query()->count());
        $this->assertSame($history, AuditEntry::query()->where('subject_type', 'person')->where('subject_id', (string) $person->id)->pluck('id')->all());
    }

    public function test_unverified_contact_never_causes_a_link(): void
    {
        $stranger = $this->personWithEmail('anna@example.test', verified: false);
        $account = User::factory()->unverified()->create(['email' => 'anna@example.test']);

        $this->verifyThroughLink($account);

        $this->assertFalse($account->fresh()->person->is($stranger), 'Niezweryfikowany kontakt nie łączy konta z osobą.');
        $this->assertSame(2, Person::query()->count());
    }

    public function test_unverified_account_is_not_linked(): void
    {
        $this->personWithEmail('anna@example.test');
        $account = User::factory()->unverified()->create(['email' => 'anna@example.test']);

        $this->assertSame(ResolveAccountPerson::UNCHANGED, $this->app->make(ResolveAccountPerson::class)->handle($account));
        $this->assertNull($account->fresh()->person_id);
    }

    public function test_ambiguous_match_links_nothing_and_is_audited(): void
    {
        $this->personWithEmail('family@example.test', given: 'Maria');
        $this->personWithEmail('family@example.test', given: 'Jan');
        $account = User::factory()->unverified()->create(['email' => 'family@example.test']);

        $this->verifyThroughLink($account);

        $this->assertNull($account->fresh()->person_id);
        $this->assertSame(2, Person::query()->count(), 'Konflikt nie tworzy nowej osoby.');
        $entry = AuditEntry::query()->where('action', 'account.person_link_conflict')->sole();
        $this->assertSame(['candidates' => 2], $entry->after_values);
        $this->assertSame('failed', $entry->result->value);
    }

    public function test_person_that_already_has_another_account_is_a_conflict(): void
    {
        $person = $this->personWithEmail('shared@example.test');
        User::factory()->create(['email' => 'first@example.test', 'person_id' => $person->id]);
        $account = User::factory()->unverified()->create(['email' => 'shared@example.test']);

        $account->markEmailAsVerified();

        $this->assertSame(ResolveAccountPerson::CONFLICT, $this->app->make(ResolveAccountPerson::class)->handle($account));
        $this->assertNull($account->fresh()->person_id);
    }

    public function test_tampered_link_does_not_verify(): void
    {
        $account = User::factory()->unverified()->create(['email' => 'anna@example.test']);
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $account->id, 'hash' => sha1('other@example.test')]);

        $this->actingAs($account)->get($url)->assertForbidden();

        $this->assertFalse($account->fresh()->hasVerifiedEmail());
        $this->assertNull($account->fresh()->person_id);
    }

    public function test_ambiguous_match_opens_one_repair_case_and_reveals_nothing_to_the_holder(): void
    {
        $this->personWithEmail('family@example.test', given: 'Maria');
        $this->personWithEmail('family@example.test', given: 'Jan');
        $account = User::factory()->unverified()->create(['email' => 'family@example.test']);

        $this->verifyThroughLink($account);
        $this->app->make(ResolveAccountPerson::class)->handle($account->fresh());

        $review = PersonLinkReview::query()->sole();
        $this->assertSame(PersonLinkReviewStatus::Open, $review->status);
        $this->assertCount(2, $review->candidate_person_ids);
        $this->get('/account')->assertOk()->assertSee('dodatkowa weryfikacja')->assertSee($review->public_id)
            ->assertDontSee('Maria')->assertDontSee('Jan');
    }

    public function test_repair_links_the_account_to_the_verified_candidate(): void
    {
        $maria = $this->personWithEmail('family@example.test', given: 'Maria');
        $this->personWithEmail('family@example.test', given: 'Jan');
        $account = User::factory()->unverified()->create(['email' => 'family@example.test']);
        $this->verifyThroughLink($account);
        $review = PersonLinkReview::query()->sole();

        $this->app->make(ResolvePersonLinkReview::class)->handle($review, $maria, 'identity confirmed by operator with ID document');

        $this->assertTrue($account->fresh()->person->is($maria));
        $this->assertSame(PersonLinkReviewStatus::Resolved, $review->fresh()->status);
        $this->assertSame($maria->id, $review->fresh()->resolved_person_id);
        $this->assertNull($account->fresh()->openPersonLinkReview);
    }

    public function test_repair_can_create_a_new_person_but_never_pick_a_non_candidate(): void
    {
        $this->personWithEmail('family@example.test', given: 'Maria');
        $this->personWithEmail('family@example.test', given: 'Jan');
        $stranger = $this->personWithEmail('other@example.test', given: 'Obcy');
        $account = User::factory()->unverified()->create(['given_name' => 'Ewa', 'email' => 'family@example.test']);
        $this->verifyThroughLink($account);
        $review = PersonLinkReview::query()->sole();
        $resolve = $this->app->make(ResolvePersonLinkReview::class);

        try {
            $resolve->handle($review, $stranger, 'wrong choice');
            $this->fail('A non-candidate must be refused.');
        } catch (\LogicException) {
        }
        $resolve->handle($review, null, 'none of the candidates is the account holder');

        $this->assertSame('Ewa', $account->fresh()->person->given_name);
        $this->assertSame(4, Person::query()->count());
        $this->expectException(\LogicException::class);
        $resolve->handle($review->fresh(), null, 'second resolution');
    }
}
