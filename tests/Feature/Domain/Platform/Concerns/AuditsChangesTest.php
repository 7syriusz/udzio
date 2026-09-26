<?php

namespace Tests\Feature\Domain\Platform\Concerns;

use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class AuditsChangesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creation_records_redacted_values_without_copying_secrets(): void
    {
        $user = User::factory()->unverified()->create(['given_name' => 'Private Name', 'email' => 'private@example.test', 'remember_token' => 'secret-token']);

        $entry = AuditEntry::query()->sole();

        $this->assertSame('account.created', $entry->action);
        $this->assertSame((string) $user->id, $entry->subject_id);
        $values = $entry->after_values;
        ksort($values);
        $this->assertSame(['email' => '[REDACTED]', 'email_verified_at' => null, 'family_name' => '[REDACTED]', 'given_name' => '[REDACTED]', 'password' => '[REDACTED]', 'person_id' => null, 'remember_token' => '[REDACTED]', 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_secret' => null], $values);
        foreach (['Private Name', 'private@example.test', 'secret-token', $user->password] as $secret) {
            $this->assertStringNotContainsString($secret, $entry->toJson());
        }
    }

    public function test_change_records_previous_and_new_values_actor_and_reason(): void
    {
        $user = User::factory()->unverified()->create();
        $reason = $this->app->make(AuditReason::class);

        $this->app->make(ActorContext::class)->runAs(Actor::account('operator-17'), fn () => $reason->because('verified by operator', fn () => $user->forceFill(['email_verified_at' => '2026-09-26 12:00:00'])->save()));

        $entry = AuditEntry::query()->where('action', 'account.updated')->sole();
        $this->assertSame(['email_verified_at' => null], $entry->before_values);
        $this->assertSame(['email_verified_at' => '2026-09-26 12:00:00'], $entry->after_values);
        $this->assertSame('operator-17', $entry->actor_id);
        $this->assertSame((string) $user->id, $entry->subject_id);
        $this->assertSame('verified by operator', $entry->reason);
    }

    public function test_no_change_or_timestamp_only_change_creates_no_audit(): void
    {
        $user = User::factory()->create();

        $user->save();
        $this->travel(1)->minutes();
        $user->touch();

        $this->assertDatabaseCount('audit_entries', 1);
    }

    public function test_redacted_change_is_still_recorded(): void
    {
        $user = User::factory()->create(['email' => 'before@example.test']);

        $this->app->make(AuditReason::class)->because('contact correction', fn () => $user->update(['email' => 'after@example.test']));

        $entry = AuditEntry::query()->where('action', 'account.updated')->sole();
        $this->assertSame(['email' => '[REDACTED]'], $entry->before_values);
        $this->assertSame(['email' => '[REDACTED]'], $entry->after_values);
    }

    public function test_missing_reason_rolls_back_model_change(): void
    {
        $user = User::factory()->create(['given_name' => 'Original']);

        try {
            $user->update(['given_name' => 'Changed']);
            $this->fail('Missing reason must prevent change.');
        } catch (LogicException $exception) {
            $this->assertSame('A reason is required for an audited change.', $exception->getMessage());
        }

        $this->assertSame('Original', $user->fresh()->given_name);
        $this->assertDatabaseCount('audit_entries', 1);
    }

    public function test_audit_failure_rolls_back_model_write(): void
    {
        $user = User::factory()->create(['given_name' => 'Original']);
        Event::listen('eloquent.creating: '.AuditEntry::class, function (): never {
            throw new RuntimeException('audit unavailable');
        });

        try {
            $this->app->make(AuditReason::class)->because('profile correction', fn () => $user->update(['given_name' => 'Changed']));
            $this->fail('Audit failure must roll back the write.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertSame('Original', $user->fresh()->given_name);
        $this->assertDatabaseCount('audit_entries', 1);
        $this->assertNull($this->app->make(AuditReason::class)->current());
    }

    public function test_stale_model_records_locked_database_value_as_previous_state(): void
    {
        $user = User::factory()->unverified()->create();
        $stale = $user->fresh();
        $reason = $this->app->make(AuditReason::class);
        $reason->because('first verification', fn () => $user->forceFill(['email_verified_at' => '2026-09-25 12:00:00'])->save());

        $reason->because('correct verification date', fn () => $stale->forceFill(['email_verified_at' => '2026-09-26 12:00:00'])->save());

        $entry = AuditEntry::query()->where('action', 'account.updated')->orderByDesc('id')->firstOrFail();
        $this->assertSame(['email_verified_at' => '2026-09-25 12:00:00'], $entry->before_values);
        $this->assertSame(['email_verified_at' => '2026-09-26 12:00:00'], $entry->after_values);
    }

    public function test_quiet_save_and_delete_cannot_skip_audit(): void
    {
        $user = User::factory()->create();
        $reason = $this->app->make(AuditReason::class);
        $user->given_name = 'Changed';

        $reason->because('profile correction', fn () => $user->saveQuietly());
        $reason->because('account deletion', fn () => $user->deleteQuietly());

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(['account.created', 'account.updated', 'account.deleted'], AuditEntry::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame('account deletion', AuditEntry::query()->where('action', 'account.deleted')->sole()->reason);
    }

    public function test_outer_transaction_failure_discards_creation_and_its_audit(): void
    {
        try {
            DB::transaction(function (): void {
                User::factory()->create(['email' => 'rollback@example.test']);
                throw new RuntimeException('outer failure');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('outer failure', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_existing_identity_cannot_be_changed(): void
    {
        $user = User::factory()->create();
        $originalId = $user->id;
        $user->id = $originalId + 1000;

        try {
            $this->app->make(AuditReason::class)->because('invalid identity change', fn () => $user->save());
            $this->fail('Identity change must be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('An audited identity cannot be changed.', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $originalId]);
        $this->assertDatabaseMissing('users', ['id' => $originalId + 1000]);
        $this->assertDatabaseCount('audit_entries', 1);
    }

    public function test_schema_rollback_preserves_recorded_values(): void
    {
        User::factory()->create();
        $migration = require database_path('migrations/2026_09_26_062457_add_changes_to_audit_entries_table.php');

        try {
            $migration->down();
            $this->fail('Recorded values must be preserved.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot remove recorded audit changes', $exception->getMessage());
        }

        $this->assertSame('[REDACTED]', AuditEntry::query()->sole()->after_values['password']);
    }
}
