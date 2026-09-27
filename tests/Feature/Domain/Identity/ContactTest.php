<?php

namespace Tests\Feature\Domain\Identity;

use App\Domain\Identity\Actions\AddContact;
use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Actions\RemoveContact;
use App\Domain\Identity\Actions\RequestContactVerification;
use App\Domain\Identity\Actions\VerifyContact;
use App\Domain\Identity\Contracts\ContactCodeSender;
use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Exceptions\ContactChannelUnavailable;
use App\Domain\Identity\Exceptions\ContactVerificationFailed;
use App\Domain\Identity\Models\Contact;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Notifications\ContactVerificationCode;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function person(string $given = 'Anna'): Person
    {
        return $this->app->make(RegisterPerson::class)->handle(['given_name' => $given, 'family_name' => 'Nowak']);
    }

    private function add(Person $owner, ContactChannel $channel, string $value): Contact
    {
        return $this->app->make(AddContact::class)->handle($owner, $channel, $value);
    }

    /** Requests a code and returns it as delivered by e-mail. */
    private function requestCode(Contact $contact): string
    {
        Notification::fake();
        $this->app->make(RequestContactVerification::class)->handle($contact);
        $code = null;
        Notification::assertSentTo(new AnonymousNotifiable, ContactVerificationCode::class, function ($notification, $channels, $notifiable) use ($contact, &$code) {
            $code = $notification->code;

            return $notifiable->routes['mail'] === $contact->value;
        });

        return $code;
    }

    /** @return array<string, array{0: ContactChannel, 1: string, 2: string}> */
    public static function normalized(): array
    {
        return [
            'e-mail wielkie litery i spacje' => [ContactChannel::Email, '  Anna.Nowak@Example.TEST ', 'anna.nowak@example.test'],
            'telefon krajowy' => [ContactChannel::Phone, '600 100 200', '+48600100200'],
            'telefon z 00' => [ContactChannel::Phone, '0049-30-1234567', '+49301234567'],
            'telefon z +' => [ContactChannel::Phone, '+48 (600) 100-200', '+48600100200'],
        ];
    }

    #[DataProvider('normalized')]
    public function test_contact_values_are_normalized(ContactChannel $channel, string $input, string $expected): void
    {
        $this->assertSame($expected, $this->add($this->person(), $channel, $input)->value);
    }

    public function test_invalid_values_are_refused(): void
    {
        foreach ([[ContactChannel::Email, 'not-an-email'], [ContactChannel::Phone, '12'], [ContactChannel::Phone, 'abc']] as [$channel, $value]) {
            try {
                $this->add($this->person(), $channel, $value);
                $this->fail("{$value} must be refused.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('value', $e->errors());
            }
        }
        $this->assertSame(0, Contact::query()->count());
    }

    public function test_the_same_address_on_two_people_does_not_merge_them(): void
    {
        $mother = $this->person('Maria');
        $father = $this->person('Jan');

        $this->add($mother, ContactChannel::Email, 'family@example.test');
        $this->add($father, ContactChannel::Email, 'FAMILY@example.test');

        $this->assertSame(2, Person::query()->count());
        $this->assertSame([$mother->id, $father->id], Contact::query()->where('value', 'family@example.test')->orderBy('id')->pluck('person_id')->all());
    }

    public function test_a_guardian_contact_belongs_to_the_guardian_not_to_the_child(): void
    {
        $guardian = $this->person('Maria');
        $child = $this->person('Zosia');

        $contact = $this->add($guardian, ContactChannel::Phone, '600100200');

        $this->assertTrue($contact->person->is($guardian));
        $this->assertCount(0, $child->contacts, 'Kontakt opiekuna nie staje się tożsamością dziecka.');
    }

    public function test_one_person_cannot_hold_the_same_active_contact_twice_but_may_add_it_again_after_removal(): void
    {
        $person = $this->person();
        $first = $this->add($person, ContactChannel::Email, 'anna@example.test');

        try {
            $this->add($person, ContactChannel::Email, 'anna@example.test');
            $this->fail('Duplicate active contact must be refused.');
        } catch (ValidationException) {
        }

        $this->app->make(RemoveContact::class)->handle($first, 'address no longer used');
        $again = $this->add($person, ContactChannel::Email, 'anna@example.test');

        $this->assertNotNull($first->fresh()->removed_at, 'Usunięty kontakt zostaje w historii.');
        $this->assertCount(2, $person->contacts);
        $this->assertSame(1, $person->contacts()->active()->count());
        $this->assertFalse($again->isVerified());
    }

    public function test_address_and_owner_cannot_change(): void
    {
        $contact = $this->add($this->person(), ContactChannel::Email, 'anna@example.test');

        $this->expectException(LogicException::class);
        $this->app->make(AuditReason::class)->because('typo', fn () => $contact->update(['value' => 'other@example.test']));
    }

    public function test_verification_with_the_delivered_code(): void
    {
        $contact = $this->add($this->person(), ContactChannel::Email, 'anna@example.test');
        $code = $this->requestCode($contact);

        $this->app->make(VerifyContact::class)->handle($contact, $code);

        $this->assertTrue($contact->fresh()->isVerified());
        $entry = AuditEntry::query()->where('action', 'contact.updated')->sole();
        $this->assertSame('contact verified by code', $entry->reason);
        $this->assertSame(0, \DB::table('contact_verifications')->where('code_hash', $code)->count(), 'Kod nie jest przechowywany jawnie.');
    }

    public function test_wrong_codes_are_counted_and_the_code_dies_after_the_limit(): void
    {
        $contact = $this->add($this->person(), ContactChannel::Email, 'anna@example.test');
        $code = $this->requestCode($contact);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->app->make(VerifyContact::class)->handle($contact, $wrong);
                $this->fail('Wrong code must fail.');
            } catch (ContactVerificationFailed) {
            }
        }

        $this->expectException(ContactVerificationFailed::class);
        $this->app->make(VerifyContact::class)->handle($contact, $code);
    }

    public function test_expired_or_replaced_code_does_not_verify(): void
    {
        $contact = $this->add($this->person(), ContactChannel::Email, 'anna@example.test');
        $old = $this->requestCode($contact);
        $new = $this->requestCode($contact);

        if ($old !== $new) {
            try {
                $this->app->make(VerifyContact::class)->handle($contact, $old);
                $this->fail('Replaced code must fail.');
            } catch (ContactVerificationFailed) {
            }
        }

        $this->travel(31)->minutes();
        $this->expectException(ContactVerificationFailed::class);
        $this->app->make(VerifyContact::class)->handle($contact, $new);
    }

    public function test_code_requests_are_limited(): void
    {
        $contact = $this->add($this->person(), ContactChannel::Email, 'anna@example.test');
        for ($i = 0; $i < 5; $i++) {
            $this->requestCode($contact);
        }

        $this->expectException(ValidationException::class);
        $this->app->make(RequestContactVerification::class)->handle($contact);
    }

    public function test_removed_contact_cannot_be_verified(): void
    {
        $contact = $this->add($this->person(), ContactChannel::Email, 'anna@example.test');
        $code = $this->requestCode($contact);
        $this->app->make(RemoveContact::class)->handle($contact, 'removed');

        $this->expectException(ContactVerificationFailed::class);
        $this->app->make(VerifyContact::class)->handle($contact->fresh(), $code);
    }

    public function test_contact_audit_hides_the_address(): void
    {
        $this->add($this->person(), ContactChannel::Email, 'anna@example.test');

        $entry = AuditEntry::query()->where('action', 'contact.created')->sole();
        $this->assertSame('[REDACTED]', $entry->after_values['value']);
        $this->assertSame('email', $entry->after_values['channel']);
    }

    public function test_phone_without_an_sms_operator_gets_no_code_and_cannot_become_verified(): void
    {
        Notification::fake();
        $phone = $this->add($this->person(), ContactChannel::Phone, '600100200');

        $this->assertFalse($phone->canBeVerified());
        try {
            $this->app->make(RequestContactVerification::class)->handle($phone);
            $this->fail('Phone verification must be unavailable.');
        } catch (ContactChannelUnavailable) {
        }
        $this->assertSame(0, \DB::table('contact_verifications')->count(), 'Nie powstaje żaden kod.');
        Notification::assertNothingSent();

        $this->expectException(LogicException::class);
        $this->app->make(AuditReason::class)->because('direct attempt', fn () => $phone->update(['verified_at' => now()]));
    }

    public function test_a_configured_provider_makes_the_phone_channel_verifiable(): void
    {
        $sender = new class implements ContactCodeSender
        {
            public ?string $code = null;

            public function send(Contact $contact, string $code, int $ttlMinutes): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance('test.sms-sender', $sender);
        config(['identity.contacts.senders.phone' => 'test.sms-sender']);
        $phone = $this->add($this->person(), ContactChannel::Phone, '600100200');

        $this->app->make(RequestContactVerification::class)->handle($phone);
        $this->app->make(VerifyContact::class)->handle($phone, $sender->code);

        $this->assertTrue($phone->fresh()->isVerified());
    }
}
