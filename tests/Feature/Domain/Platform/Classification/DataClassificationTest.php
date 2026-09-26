<?php

namespace Tests\Feature\Domain\Platform\Classification;

use App\Domain\Platform\Actions\RecordProtectedRead;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Classification\ClassifiedData;
use App\Domain\Platform\Enums\DataClass;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\Fixtures\ValidityProbe;
use Tests\TestCase;

class DataClassificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_class_has_a_complete_policy(): void
    {
        foreach (DataClass::cases() as $class) {
            $policy = $class->policy();
            $this->assertIsBool($policy->auditValues, $class->value);
        }
        $this->assertFalse(DataClass::Secret->policy()->auditValues);
        $this->assertFalse(DataClass::SpecialCategory->policy()->auditValues);
        $this->assertTrue(DataClass::Secret->policy()->auditReads);
        $this->assertTrue(DataClass::SpecialCategory->policy()->auditReads);
    }

    public function test_audit_redacts_by_class_and_keeps_that_the_change_happened(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'old@example.test']);

        $this->app->make(AuditReason::class)->because('correction', fn () => $user->update(['email' => 'new@example.test']));

        $entry = AuditEntry::query()->where('action', 'account.updated')->sole();
        $this->assertSame(['email' => '[REDACTED]'], $entry->before_values);
        $this->assertStringNotContainsString('example.test', $entry->toJson());
    }

    public function test_export_follows_the_class_policy(): void
    {
        $user = User::factory()->make(['given_name' => 'Anna', 'email' => 'anna@example.test', 'email_verified_at' => null]);

        $exported = ClassifiedData::forExport($user, [...$user->getAttributes(), 'unlisted' => 'x']);

        $this->assertSame('Anna', $exported['given_name'], 'RESTRICTED jest eksportowane uprawnionym.');
        $this->assertSame('anna@example.test', $exported['email']);
        $this->assertArrayNotHasKey('password', $exported, 'SECRET nie trafia do eksportu.');
        $this->assertArrayNotHasKey('remember_token', $exported);
        $this->assertArrayNotHasKey('unlisted', $exported, 'Pole bez klasyfikacji nie trafia do eksportu.');
    }

    public function test_policy_is_configuration_not_code(): void
    {
        config(['data_classification.policies.restricted.audit_values' => true, 'data_classification.policies.restricted.export' => 'redacted']);
        $user = User::factory()->make(['email' => 'anna@example.test']);

        $this->assertSame(['email' => 'anna@example.test'], ClassifiedData::forAudit($user, ['email' => 'anna@example.test']));
        $this->assertSame(['email' => '[REDACTED]'], ClassifiedData::forExport($user, ['email' => 'anna@example.test']));
    }

    public function test_special_category_is_redacted_in_export_and_its_reads_are_audited(): void
    {
        $probe = new class extends ValidityProbe
        {
            public function dataClassification(): array
            {
                return [...parent::dataClassification(), 'function' => DataClass::SpecialCategory];
            }
        };
        $probe->forceFill(['id' => 7, 'context_ref' => 'ORG-1', 'function' => 'party member']);

        $this->assertSame(['function' => '[REDACTED]', 'context_ref' => 'ORG-1'], ClassifiedData::forExport($probe, ['function' => 'party member', 'context_ref' => 'ORG-1']));

        $reads = $this->app->make(RecordProtectedRead::class);
        $this->assertFalse($reads->forModel($probe, ['context_ref'], 'membership list'));
        $this->assertTrue($reads->forModel($probe, ['context_ref', 'function'], 'membership list'));

        $entry = AuditEntry::on('audit')->where('action', 'data.read')->sole();
        $this->assertSame(['fields' => ['function']], $entry->after_values);
        $this->assertSame('ORG-1', $entry->organization_id);
        $this->assertSame('7', $entry->subject_id);
    }

    public function test_unclassified_field_cannot_be_written_by_an_audited_model(): void
    {
        $probe = new class extends ValidityProbe
        {
            public function dataClassification(): array
            {
                return array_diff_key(parent::dataClassification(), ['function' => true]);
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unclassified fields cannot be written: function.');
        $probe::startPeriod(['person_ref' => 'P-1', 'context_ref' => 'ORG-1', 'function' => 'member'], now());
    }

    public function test_classifying_an_unknown_field_is_refused(): void
    {
        $this->expectException(LogicException::class);
        ClassifiedData::forAudit(new User, ['nickname' => 'x']);
    }
}
