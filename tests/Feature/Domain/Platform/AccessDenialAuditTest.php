<?php

namespace Tests\Feature\Domain\Platform;

use App\Domain\Platform\Actions\RecordAccessDenial;
use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Enums\AuditResult;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccessDenialAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::define('probe.manage', fn () => false);
        Route::get('/_probe/gate', fn () => Gate::authorize('probe.manage'))->name('probe.gate');
        Route::get('/_probe/abort', fn () => abort(403))->name('probe.abort');
        Route::get('/_probe/hidden', fn () => throw (new AccessDenied('person', '01J0000000000000000000PERS', 'org-7', 'person.view'))->hideAsNotFound())->name('probe.hidden');
    }

    private function denials(): Builder
    {
        return AuditEntry::on('audit')->where('action', 'access.denied');
    }

    public function test_gate_denial_is_rejected_with_403_and_audited_with_actor_and_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/_probe/gate?email=hidden@example.test')->assertForbidden();

        $entry = $this->denials()->sole();
        $this->assertSame(AuditResult::Denied, $entry->result);
        $this->assertSame(ActorType::Account, $entry->actor_type);
        $this->assertSame((string) $user->id, $entry->actor_id);
        $this->assertSame('route', $entry->subject_type);
        $this->assertSame('probe.gate', $entry->subject_id);
        $this->assertEqualsCanonicalizing(['status' => 403, 'method' => 'GET', 'route' => 'probe.gate'], $entry->after_values);
        $this->assertStringNotContainsString('hidden@example.test', $entry->toJson());
    }

    public function test_hidden_denial_returns_404_but_is_audited_as_denial_of_the_requested_subject(): void
    {
        $this->actingAs(User::factory()->create())->get('/_probe/hidden')->assertNotFound();

        $entry = $this->denials()->sole();
        $this->assertSame('person', $entry->subject_type);
        $this->assertSame('01J0000000000000000000PERS', $entry->subject_id);
        $this->assertSame('org-7', $entry->organization_id);
        $this->assertEqualsCanonicalizing(['status' => 404, 'method' => 'GET', 'route' => 'probe.hidden', 'ability' => 'person.view'], $entry->after_values);
    }

    public function test_plain_abort_403_is_audited_and_anonymous_actor_is_kept(): void
    {
        $this->get('/_probe/abort')->assertForbidden();

        $entry = $this->denials()->sole();
        $this->assertSame(ActorType::Anonymous, $entry->actor_type);
        $this->assertNull($entry->actor_id);
        $this->assertSame('probe.abort', $entry->subject_id);
    }

    public function test_audit_failure_never_changes_the_denial_response(): void
    {
        $this->app->instance(RecordAccessDenial::class, new class
        {
            public function handle(mixed ...$arguments): never
            {
                throw new \RuntimeException('audit database unavailable');
            }
        });
        Log::spy();

        $this->get('/_probe/gate')->assertForbidden();

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_genuine_not_found_is_not_a_denial(): void
    {
        $this->get('/_probe/does-not-exist')->assertNotFound();

        $this->assertSame(0, $this->denials()->count());
    }

    public function test_denial_audit_is_throttled_per_actor_and_target(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < RecordAccessDenial::MAX_PER_MINUTE + 5; $i++) {
            $this->actingAs($user)->get('/_probe/gate')->assertForbidden();
        }
        $other = User::factory()->create();
        $this->actingAs($other)->get('/_probe/gate')->assertForbidden();

        $this->assertSame(RecordAccessDenial::MAX_PER_MINUTE, $this->denials()->where('actor_id', (string) $user->id)->count());
        $this->assertSame(1, $this->denials()->where('actor_id', (string) $other->id)->count(), 'Limit dotyczy pary wykonawca–cel.');
    }
}
