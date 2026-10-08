<?php

namespace Tests\Feature\Domain\Identity;

use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Actions\UpdatePersonDetails;
use App\Domain\Identity\Models\Person;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ValidityProbe;
use Tests\TestCase;

class PersonTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function register(array $data = []): Person
    {
        return $this->app->make(RegisterPerson::class)->handle([...['given_name' => 'Anna', 'family_name' => 'Nowak'], ...$data]);
    }

    public function test_person_exists_without_an_account_and_has_a_public_identifier(): void
    {
        $person = $this->register();

        $this->assertTrue(Str::isUlid($person->public_id));
        $this->assertSame('public_id', $person->getRouteKeyName());
        $this->assertArrayNotHasKey('birth_date', $person->fresh()->getAttributes(), 'PERSON nie ma globalnej daty urodzenia (Z-019).');
        $this->assertSame(0, User::query()->count());
    }

    public function test_new_contexts_refer_to_the_same_person_instead_of_duplicating_it(): void
    {
        $person = $this->register();

        foreach (['ORG-1', 'ORG-2', 'SCENARIO-9'] as $context) {
            ValidityProbe::startPeriod(['person_ref' => $person->public_id, 'context_ref' => $context, 'function' => 'member'], now());
        }

        $this->assertSame(1, Person::query()->count());
        $this->assertSame(3, ValidityProbe::query()->where('person_ref', $person->public_id)->count());
    }

    public function test_registration_never_matches_an_existing_person_automatically(): void
    {
        $this->register();
        $this->register();

        $this->assertSame(2, Person::query()->count(), 'Te same dane mogą należeć do dwóch osób; duplikaty rozwiązuje MERGE.');
    }

    public function test_creation_audit_hides_personal_data_and_has_no_organization(): void
    {
        $person = $this->register();

        $entry = AuditEntry::query()->where('action', 'person.created')->sole();
        $this->assertSame((string) $person->id, $entry->subject_id);
        $this->assertNull($entry->organization_id, 'PERSON nie należy do organizacji.');
        $this->assertSame('[REDACTED]', $entry->after_values['family_name']);
        $this->assertSame($person->public_id, $entry->after_values['public_id']);
        $this->assertStringNotContainsString('Nowak', $entry->toJson());
    }

    public function test_correction_keeps_identity_and_records_the_reason(): void
    {
        $person = $this->register();
        $publicId = $person->public_id;

        $this->app->make(UpdatePersonDetails::class)->handle($person, ['family_name' => 'Kowalska'], 'name change after marriage');

        $fresh = $person->fresh();
        $this->assertSame([$publicId, 'Kowalska', 'Anna'], [$fresh->public_id, $fresh->family_name, $fresh->given_name]);
        $entry = AuditEntry::query()->where('action', 'person.updated')->sole();
        $this->assertSame('name change after marriage', $entry->reason);
        $this->assertSame(['family_name' => '[REDACTED]'], $entry->after_values);
    }

    public function test_public_identifier_cannot_be_changed(): void
    {
        $person = $this->register();

        $this->expectException(\LogicException::class);
        $this->app->make(AuditReason::class)->because('attempt', fn () => $person->forceFill(['public_id' => (string) Str::ulid()])->save());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidData(): array
    {
        return [
            'brak imienia' => [['given_name' => ''], 'given_name'],
            'same spacje' => [['family_name' => '   '], 'family_name'],
        ];
    }

    #[DataProvider('invalidData')]
    public function test_invalid_data_is_refused(array $data, string $field): void
    {
        try {
            $this->register($data);
            $this->fail('Validation expected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
        $this->assertSame(0, Person::query()->count());
    }
}
