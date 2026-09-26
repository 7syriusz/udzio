<?php

namespace Tests\Feature\Domain\Platform;

use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\RelationStatus;
use App\Domain\Platform\Models\AuditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Fixtures\DefinitionProbe;
use Tests\Fixtures\DefinitionResultProbe;
use Tests\Fixtures\ValidityProbe;
use Tests\TestCase;

/**
 * E1 closing scenario across the platform patterns: A5-03, A5-04, A5-05, A5-11, A5-16 (and A5-15).
 * A registry integration (technical ACTOR) changes a person's function in an organization (SUBJECT),
 * a result is produced by a versioned rule, then the rule changes.
 */
class E1AcceptanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_platform_patterns_work_together(): void
    {
        $context = $this->app->make(ActorContext::class);
        $reason = $this->app->make(AuditReason::class);
        $at = fn (string $day) => CarbonImmutable::parse($day, 'UTC');

        // A5-05: relation with period, status and history.
        $membership = ValidityProbe::startPeriod(['person_ref' => 'P-7', 'context_ref' => 'ORG-1', 'function' => 'member'], $at('2026-01-01'));
        $context->runAs(Actor::integration('registry-sync'), fn () => $reason->because(
            'board resolution 12/2026',
            fn () => $membership->transition(RelationStatus::Active, $at('2026-05-01'), ['function' => 'treasurer']),
        ));

        $this->assertSame(['member', 'treasurer'], $membership->history()->pluck('function')->all());
        $this->assertSame('member', ValidityProbe::query()->where('person_ref', 'P-7')->activeAt($at('2026-04-30'))->sole()->function);

        // A5-03, A5-04, A5-15: technical ACTOR separate from business SUBJECT; reason, before and after kept.
        $closing = AuditEntry::query()->where('action', 'validity_probe.updated')->sole();
        $this->assertSame(['integration', 'registry-sync'], [$closing->actor_type->value, $closing->actor_id]);
        $this->assertSame(['validity_probe', (string) $membership->id, 'ORG-1'], [$closing->subject_type, $closing->subject_id, $closing->organization_id]);
        $this->assertSame('board resolution 12/2026', $closing->reason);
        $this->assertNull($closing->before_values['valid_to']);
        $this->assertSame('2026-05-01 00:00:00.000000', $closing->after_values['valid_to']);
        $opened = AuditEntry::query()->where('action', 'validity_probe.created')->latest('id')->first();
        $this->assertSame('treasurer', $opened->after_values['function']);

        // A5-11, A5-16: result names the exact rule version; a later rule change does not alter it.
        $rule = DefinitionProbe::create(['organization_ref' => 'ORG-1', 'name' => 'Dues', 'rules' => ['monthly_minor' => 1000]]);
        $result = DefinitionResultProbe::create(['definition_version_id' => $rule->publishVersion()->id, 'score' => 12000]);
        $reason->because('dues raised', fn () => $rule->update(['rules' => ['monthly_minor' => 1500]]));
        $rule->publishVersion();

        $this->assertSame(1, $result->fresh()->definitionVersion->version);
        $this->assertSame(1000, $result->fresh()->definitionVersion->content['rules']['monthly_minor']);
        $this->assertSame(2, $rule->latestVersion()->version);
    }
}
