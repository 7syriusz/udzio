<?php

namespace Tests\Feature\Domain\Platform\Actions;

use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\AuditResult;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RecordAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_preserves_independent_actor_subject_and_context(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 26)->setTime(12, 30, 10, 123456));
        $writer = $this->app->make(RecordAudit::class);

        $entry = $this->app->make(ActorContext::class)->runAs(Actor::account('17'), fn () => $writer->handle(
            'participation.corrected', 'person', 'child-42', AuditResult::Succeeded,
            'org-8', 'operator correction', '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ));

        $this->assertDatabaseHas('audit_entries', [
            'id' => $entry->id, 'actor_type' => 'account', 'actor_id' => '17',
            'subject_type' => 'person', 'subject_id' => 'child-42', 'organization_id' => 'org-8',
            'action' => 'participation.corrected', 'result' => 'succeeded', 'reason' => 'operator correction',
            'correlation_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'occurred_at' => '2026-09-26 12:30:10.123456',
        ]);
        $this->assertTrue(Str::isUlid($entry->public_id));
        $this->assertSame('2026-09-26 12:30:10.123456', $entry->fresh()->occurred_at->format('Y-m-d H:i:s.u'));
    }

    public function test_anonymous_denial_does_not_fabricate_an_identity(): void
    {
        $writer = $this->app->make(RecordAudit::class);

        $entry = $this->app->make(ActorContext::class)->runAs(Actor::anonymous(), fn () => $writer->handle(
            'access.denied', 'route', 'protected-area', AuditResult::Denied,
        ));

        $this->assertDatabaseHas('audit_entries', ['id' => $entry->id, 'actor_type' => 'anonymous', 'actor_id' => null, 'organization_id' => null, 'result' => 'denied']);
        $this->assertTrue(Str::isUlid($entry->correlation_id));
    }

    #[DataProvider('mutations')]
    public function test_rejects_mutation_without_losing_history(string $operation, bool $sql): void
    {
        $entry = $this->app->make(RecordAudit::class)->handle('record.created', 'record', '42');

        try {
            match ($operation) {
                'model-update' => $entry->forceFill(['action' => 'record.changed'])->save(),
                'model-delete' => $entry->delete(),
                'sql-update' => DB::table('audit_entries')->where('id', $entry->id)->update(['action' => 'record.changed']),
                'sql-delete' => DB::table('audit_entries')->where('id', $entry->id)->delete(),
                'quiet' => $entry->forceFill(['action' => 'record.changed'])->saveQuietly(),
                'upsert' => DB::table('audit_entries')->upsert([$entry->getRawOriginal()], ['public_id'], ['reason']),
            };
            $this->fail('Audit mutation must be rejected.');
        } catch (QueryException $exception) {
            $this->assertTrue($sql);
            $this->assertSame('45000', $exception->errorInfo[0]);
        } catch (LogicException $exception) {
            $this->assertFalse($sql);
            $this->assertSame('Audit entries are append-only.', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_entries', ['id' => $entry->id, 'action' => 'record.created']);
    }

    public static function mutations(): array
    {
        return [['model-update', false], ['model-delete', false], ['sql-update', true], ['sql-delete', true], ['quiet', true], ['upsert', true]];
    }

    public function test_business_failure_rolls_back_audit_and_business_data_together(): void
    {
        $writer = $this->app->make(RecordAudit::class);

        try {
            DB::transaction(function () use ($writer): void {
                $user = User::factory()->create(['email' => 'rollback@example.test']);
                $writer->handle('account.created', 'account', (string) $user->id);
                throw new RuntimeException('business failure');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('business failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_entries', 0);
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
    }

    public function test_correlation_groups_facts_without_merging_organizations(): void
    {
        $writer = $this->app->make(RecordAudit::class);
        $correlation = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        $first = $writer->handle('record.created', 'record', '42', organizationId: 'org-a', correlationId: $correlation);
        $second = $writer->handle('record.created', 'record', '42', organizationId: 'org-b', correlationId: $correlation);

        $this->assertNotSame($first->public_id, $second->public_id);
        $this->assertSame(['org-a', 'org-b'], AuditEntry::query()->where('correlation_id', $correlation)->orderBy('id')->pluck('organization_id')->all());
    }

    public function test_invalid_input_writes_no_audit(): void
    {
        try {
            $this->app->make(RecordAudit::class)->handle('', 'record', '42', correlationId: 'invalid');
            $this->fail('Invalid input must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['action', 'correlation_id'], array_keys($exception->errors()));
        }

        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_schema_rollback_preserves_existing_history(): void
    {
        $entry = $this->app->make(RecordAudit::class)->handle('record.created', 'record', '42');
        $migration = require database_path('migrations/2026_09_26_012451_create_audit_entries_table.php');

        try {
            $migration->down();
            $this->fail('Rollback must preserve history.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot drop audit history', $exception->getMessage());
        }

        $this->assertModelExists($entry);
    }
}
