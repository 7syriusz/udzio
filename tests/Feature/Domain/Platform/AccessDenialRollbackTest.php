<?php

namespace Tests\Feature\Domain\Platform;

use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Real commits (no test transaction): the refused business transaction is fully rolled back,
 * as in production, while the denial written on the independent `audit` connection stays.
 */
class AccessDenialRollbackTest extends TestCase
{
    use DatabaseTruncation;

    /** Committed rows must not leak into transaction-based tests that run afterwards. */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_denial_survives_rollback_of_the_refused_business_transaction(): void
    {
        $user = User::factory()->create();
        Route::post('/_probe/rollback', function () {
            DB::transaction(function (): void {
                User::factory()->create(['email' => 'rolled-back@example.test']);
                throw new AccessDenied('organization', 'org-9', 'org-9', 'organization.update');
            });
        })->name('probe.rollback');

        $this->actingAs($user)->post('/_probe/rollback', ['password' => 'Secret-123'])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'rolled-back@example.test']);
        $this->assertSame(0, AuditEntry::query()->where('subject_id', '!=', (string) $user->id)->where('action', 'account.created')->count(), 'Audyt utworzenia z wycofanej transakcji znika razem z nią.');
        $entry = AuditEntry::query()->where('action', 'access.denied')->sole();
        $this->assertSame('organization', $entry->subject_type);
        $this->assertSame('org-9', $entry->organization_id);
        $this->assertSame((string) $user->id, $entry->actor_id);
        $this->assertStringNotContainsString('Secret-123', $entry->toJson());
    }
}
