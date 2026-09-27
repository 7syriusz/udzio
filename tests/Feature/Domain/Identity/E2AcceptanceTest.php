<?php

namespace Tests\Feature\Domain\Identity;

use App\Domain\Identity\Actions\AddContact;
use App\Domain\Identity\Actions\GrantRepresentation;
use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Fixtures\ValidityProbe;
use Tests\TestCase;

/**
 * E2 closing scenario: A5-01 (one PERSON across contexts), A5-02 (account joins an existing PERSON
 * later, history kept), A5-03 (parent as ACTOR, child as SUBJECT; contact is not identity).
 */
class E2AcceptanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_identity_layer_end_to_end(): void
    {
        Notification::fake();
        $reason = $this->app->make(AuditReason::class);

        // An organizer records a mother and her daughter — no accounts yet.
        $mother = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Maria', 'family_name' => 'Nowak']);
        $daughter = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Zosia', 'family_name' => 'Nowak', 'birth_date' => '2016-03-10']);
        $email = $this->app->make(AddContact::class)->handle($mother, ContactChannel::Email, 'maria@example.test');
        $reason->because('confirmed by organizer by phone', fn () => $email->update(['verified_at' => now()]));
        $this->app->make(GrantRepresentation::class)->handle($mother, $daughter, [RepresentationScope::ProfileView, RepresentationScope::ProfileUpdate], RepresentationMethod::Document, 'birth certificate no. AB-123 checked', now()->subDay(), 'guardian stated at registration desk');

        // A5-01: two organizations refer to the same daughter; no duplicate PERSON.
        foreach (['CLUB-A', 'SCHOOL-B'] as $context) {
            ValidityProbe::startPeriod(['person_ref' => $daughter->public_id, 'context_ref' => $context, 'function' => 'participant'], now()->subDay());
        }
        $this->assertSame(2, Person::query()->count());
        $motherHistory = AuditEntry::query()->where('subject_type', 'person')->where('subject_id', (string) $mother->id)->pluck('id')->all();

        // A5-02: the mother registers an account later and verifies her e-mail — it joins her PERSON.
        $this->post('/register', ['given_name' => 'Maria', 'family_name' => 'Nowak', 'email' => 'maria@example.test',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])->assertRedirect('/account');
        $account = User::query()->sole();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $account->id, 'hash' => sha1('maria@example.test')]));
        $this->assertTrue($account->fresh()->person->is($mother));
        $this->assertSame(2, Person::query()->count());
        $this->assertSame($motherHistory, AuditEntry::query()->where('subject_type', 'person')->where('subject_id', (string) $mother->id)->pluck('id')->all());

        // A5-03: the mother (ACTOR) corrects her daughter's data (SUBJECT) from the account screen.
        $this->put("/account/represented/{$daughter->public_id}", ['given_name' => 'Zofia', 'family_name' => 'Nowak', 'birth_date' => '2016-03-10'])->assertRedirect();

        $change = AuditEntry::query()->where('action', 'person.updated')->where('subject_id', (string) $daughter->id)->sole();
        $this->assertSame(['account', (string) $account->id], [$change->actor_type->value, $change->actor_id]);
        $this->assertSame('representative updated data', $change->reason);
        $this->assertSame(1, AuditEntry::query()->where('action', 'person.acted_on_behalf')->where('subject_id', (string) $daughter->id)->count());
        $this->assertCount(0, $daughter->contacts, 'Kontakt matki nie jest tożsamością córki.');
        $this->assertSame('Zofia', $daughter->fresh()->given_name);
    }
}
