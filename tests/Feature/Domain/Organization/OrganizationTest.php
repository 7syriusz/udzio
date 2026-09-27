<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\CreateOrganization;
use App\Domain\Organization\Actions\RenameOrganization;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creation_is_independent_of_people_and_accounts_and_is_audited(): void
    {
        $organization = $this->app->make(CreateOrganization::class)->handle('  Organizacja Łąka  ');

        $this->assertSame('Organizacja Łąka', $organization->fresh()->name);
        $this->assertSame(OrganizationStatus::Active, $organization->status);
        $this->assertTrue(Str::isUlid($organization->public_id));
        $this->assertSame('public_id', $organization->getRouteKeyName());
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('users', 0);
        $entry = AuditEntry::query()->where('action', 'organization.created')->sole();
        $this->assertSame((string) $organization->id, $entry->subject_id);
        $this->assertSame($organization->public_id, $entry->organization_id);
        $this->assertSame('Organizacja Łąka', $entry->after_values['name']);
    }

    public function test_equal_names_do_not_merge_organizations(): void
    {
        $create = $this->app->make(CreateOrganization::class);
        $first = $create->handle('Wspólna nazwa');
        $second = $create->handle('Wspólna nazwa');

        $this->assertNotSame($first->public_id, $second->public_id);
        $this->assertDatabaseCount('organizations', 2);
    }

    public function test_rename_preserves_identity_and_records_old_and_new_values_and_reason(): void
    {
        $organization = Organization::factory()->create(['name' => 'Stara nazwa']);
        $other = Organization::factory()->create(['name' => 'Inna organizacja']);

        $renamed = $this->app->make(RenameOrganization::class)->handle($organization, 'Nowa nazwa', 'Uchwała o zmianie nazwy');

        $this->assertSame($organization->public_id, $renamed->public_id);
        $this->assertSame('Nowa nazwa', $organization->fresh()->name);
        $this->assertSame('Inna organizacja', $other->fresh()->name);
        $entry = AuditEntry::query()->where('action', 'organization.updated')->sole();
        $this->assertSame(['name' => 'Stara nazwa'], $entry->before_values);
        $this->assertSame(['name' => 'Nowa nazwa'], $entry->after_values);
        $this->assertSame('Uchwała o zmianie nazwy', $entry->reason);
        $this->assertSame($organization->public_id, $entry->organization_id);
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_cannot_create_or_rename_an_organization(string $name): void
    {
        $organization = Organization::factory()->create(['name' => 'Bez zmian']);
        foreach ([
            fn () => $this->app->make(CreateOrganization::class)->handle($name),
            fn () => $this->app->make(RenameOrganization::class)->handle($organization, $name, 'Korekta'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Invalid name was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('name', $exception->errors());
            }
        }

        $this->assertDatabaseCount('organizations', 1);
        $this->assertSame('Bez zmian', $organization->fresh()->name);
        $this->assertDatabaseCount('audit_entries', 1);
    }

    public static function invalidNames(): array
    {
        return ['empty' => [''], 'whitespace' => [" \t\n "], 'too long' => [str_repeat('ą', 256)]];
    }

    public function test_archiving_preserves_the_record_and_history_and_repetition_is_a_no_op(): void
    {
        $organization = Organization::factory()->create();
        $archive = $this->app->make(ArchiveOrganization::class);

        $archive->handle($organization, 'Zakończenie działalności');
        $archive->handle($organization, 'Ponowienie');

        $this->assertModelExists($organization);
        $this->assertSame(OrganizationStatus::Archived, $organization->fresh()->status);
        $entry = AuditEntry::query()->where('action', 'organization.updated')->sole();
        $this->assertEqualsCanonicalizing(['status' => 'active', 'archived_at' => null], $entry->before_values);
        $this->assertSame('archived', $entry->after_values['status']);
        $this->assertNotNull($organization->fresh()->archived_at, 'Chwila archiwizacji jest zapisana (E3.6).');
        $this->assertSame('Zakończenie działalności', $entry->reason);
        $this->assertDatabaseCount('audit_entries', 2);
    }

    public function test_stale_instance_cannot_rename_an_archived_organization(): void
    {
        $organization = Organization::factory()->create(['name' => 'Historia']);
        $this->app->make(ArchiveOrganization::class)->handle($organization, 'Archiwizacja');

        try {
            $this->app->make(RenameOrganization::class)->handle($organization, 'Nowa', 'Korekta');
            $this->fail('Archived organization was renamed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('organization', $exception->errors());
        }

        $this->assertSame('Historia', $organization->fresh()->name);
        $this->assertDatabaseCount('audit_entries', 2);
    }

    public function test_unchanged_name_does_not_add_a_false_change_to_history(): void
    {
        $organization = Organization::factory()->create(['name' => 'Bez zmian']);

        $this->app->make(RenameOrganization::class)->handle($organization, 'Bez zmian', 'Ponowienie');

        $this->assertDatabaseCount('audit_entries', 1);
    }

    public function test_rename_and_archive_require_a_nonempty_reason(): void
    {
        $organization = Organization::factory()->create(['name' => 'Bez zmian']);
        foreach ([
            fn () => $this->app->make(RenameOrganization::class)->handle($organization, 'Nowa', ' '),
            fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, ''),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Missing reason was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Audit reason', $exception->getMessage());
            }
        }

        $this->assertSame('Bez zmian', $organization->fresh()->name);
        $this->assertSame(OrganizationStatus::Active, $organization->fresh()->status);
        $this->assertDatabaseCount('audit_entries', 1);
    }

    public function test_public_identifier_cannot_be_changed(): void
    {
        $organization = Organization::factory()->create();
        $identifier = $organization->public_id;

        try {
            $this->app->make(AuditReason::class)->because('Zmiana', function () use ($organization): void {
                $organization->public_id = (string) Str::ulid();
                $organization->save();
            });
            $this->fail('Identifier was changed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot change', $exception->getMessage());
        }

        $this->assertSame($identifier, $organization->fresh()->public_id);
    }

    public function test_deletion_is_blocked_with_and_without_model_events_and_in_sql(): void
    {
        $organization = Organization::factory()->create();
        foreach ([fn () => $organization->delete(), fn () => $organization->deleteQuietly()] as $operation) {
            try {
                $operation();
                $this->fail('Organization was deleted.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('archived', $exception->getMessage());
            }
        }
        try {
            DB::table('organizations')->where('id', $organization->id)->delete();
            $this->fail('SQL deleted the organization.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
        }

        $this->assertModelExists($organization);
        $this->assertDatabaseCount('audit_entries', 1);
    }

    public function test_audit_failure_rolls_back_rename_and_archive(): void
    {
        $organization = Organization::factory()->create(['name' => 'Przed awarią']);
        Event::listen('eloquent.creating: '.AuditEntry::class, function (): never {
            throw new RuntimeException('Audit unavailable');
        });

        foreach ([
            fn () => $this->app->make(RenameOrganization::class)->handle($organization, 'Nowa', 'Korekta'),
            fn () => $this->app->make(ArchiveOrganization::class)->handle($organization, 'Archiwizacja'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Operation completed without audit.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Audit unavailable', $exception->getMessage());
            }
        }

        $this->assertSame('Przed awarią', $organization->fresh()->name);
        $this->assertSame(OrganizationStatus::Active, $organization->fresh()->status);
        $this->assertDatabaseCount('audit_entries', 1);
        $this->assertNull($this->app->make(AuditReason::class)->current());
    }
}
