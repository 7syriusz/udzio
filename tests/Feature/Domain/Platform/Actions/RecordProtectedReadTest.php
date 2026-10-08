<?php

namespace Tests\Feature\Domain\Platform\Actions;

use App\Domain\Platform\Actions\RecordProtectedRead;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Models\AuditEntry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class RecordProtectedReadTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_read_records_field_names_actor_and_purpose_but_never_values(): void
    {
        $this->app->make(ActorContext::class)->runAs(Actor::account('operator-3'), fn () => $this->app->make(RecordProtectedRead::class)
            ->handle('person', 'P-1', ['document_number', 'medical_notes', 'document_number'], 'org-1', 'verification of a document'));

        $entry = AuditEntry::on('audit')->where('action', 'data.read')->sole();
        $this->assertSame('operator-3', $entry->actor_id);
        $this->assertSame('person', $entry->subject_type);
        $this->assertSame('org-1', $entry->organization_id);
        $this->assertSame('verification of a document', $entry->reason);
        $this->assertSame(['fields' => ['document_number', 'medical_notes']], $entry->after_values);
    }

    public function test_read_stays_recorded_when_surrounding_transaction_rolls_back(): void
    {
        try {
            DB::transaction(function (): void {
                $this->app->make(RecordProtectedRead::class)->handle('person', 'P-2', ['phone']);
                throw new RuntimeException('business failure');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(1, AuditEntry::on('audit')->where('action', 'data.read')->where('subject_id', 'P-2')->count());
    }

    public function test_field_list_must_contain_only_field_names(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(RecordProtectedRead::class)->handle('person', 'P-3', ['jan@example.test']);
    }
}
