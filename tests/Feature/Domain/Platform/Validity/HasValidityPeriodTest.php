<?php

namespace Tests\Feature\Domain\Platform\Validity;

use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\RelationStatus;
use App\Domain\Platform\Exceptions\ValidityConflict;
use App\Domain\Platform\Models\AuditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\Fixtures\ValidityProbe;
use Tests\TestCase;

class HasValidityPeriodTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY = ['person_ref' => 'P-1', 'context_ref' => 'ORG-1'];

    private function because(string $reason, callable $operation): mixed
    {
        return $this->app->make(AuditReason::class)->because($reason, $operation(...));
    }

    private function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, 'UTC');
    }

    public function test_relation_can_be_read_as_of_any_moment_after_suspension_and_resumption(): void
    {
        $member = ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-01-01'));
        $suspended = $this->because('membership fee not paid', fn () => $member->transition(RelationStatus::Suspended, $this->at('2026-03-01')));
        $this->because('fee paid', fn () => $suspended->transition(RelationStatus::Active, $this->at('2026-04-01')));

        $activeOn = fn (string $day) => ValidityProbe::query()->where(self::KEY)->activeAt($this->at($day))->exists();
        $this->assertFalse($activeOn('2025-12-31'));
        $this->assertTrue($activeOn('2026-02-15'));
        $this->assertFalse($activeOn('2026-03-15'), 'Zawieszona relacja nie jest aktywna.');
        $this->assertSame(RelationStatus::Suspended, ValidityProbe::query()->where(self::KEY)->effectiveAt($this->at('2026-03-15'))->sole()->status);
        $this->assertTrue($activeOn('2026-04-01'));
        $this->assertTrue($activeOn('2030-01-01'));
    }

    public function test_change_of_function_keeps_previous_function_and_period_in_history(): void
    {
        $member = ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-01-01'));

        $this->because('elected treasurer', fn () => $member->transition(RelationStatus::Active, $this->at('2026-06-01'), ['function' => 'treasurer']));

        $history = $member->history();
        $this->assertSame(['member', 'treasurer'], $history->pluck('function')->all());
        $this->assertTrue($history[0]->valid_to->equalTo($this->at('2026-06-01')));
        $this->assertNull($history[1]->valid_to);
        $this->assertSame('member', ValidityProbe::query()->where(self::KEY)->activeAt($this->at('2026-05-31 23:59:59'))->sole()->function);
        $this->assertSame('treasurer', ValidityProbe::query()->where(self::KEY)->activeAt($this->at('2026-06-01'))->sole()->function);
    }

    public function test_ending_closes_the_relation_and_a_later_period_can_start_again(): void
    {
        $member = ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-01-01'));

        $this->because('resigned', fn () => $member->end($this->at('2026-02-01')));
        $this->assertFalse(ValidityProbe::query()->where(self::KEY)->activeAt($this->at('2026-02-01'))->exists());

        ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-05-01'));
        $this->assertCount(2, $member->history());
        $this->assertTrue(ValidityProbe::query()->where(self::KEY)->activeAt($this->at('2026-05-01'))->exists());
    }

    public function test_periods_of_one_relation_cannot_overlap(): void
    {
        $member = ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-01-01'));

        try {
            ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-01-15'));
            $this->fail('Second open period must be refused.');
        } catch (ValidityConflict) {
        }
        $this->because('resigned', fn () => $member->end($this->at('2026-02-01')));

        $this->expectException(ValidityConflict::class);
        ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-01-20'));
    }

    public function test_other_relations_of_the_same_person_are_independent(): void
    {
        ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-01-01'));
        ValidityProbe::startPeriod(['person_ref' => 'P-1', 'context_ref' => 'ORG-2', 'function' => 'member'], $this->at('2026-01-01'));

        $this->assertSame(2, ValidityProbe::query()->where('person_ref', 'P-1')->activeAt($this->at('2026-01-02'))->count());
    }

    public function test_history_cannot_be_rewritten(): void
    {
        $member = ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-01-01'));
        $this->because('resigned', fn () => $member->end($this->at('2026-02-01')));
        $closed = $member->fresh();

        foreach ([['valid_to' => $this->at('2026-03-01')], ['function' => 'chair']] as $change) {
            try {
                $this->because('correction attempt', fn () => $closed->fresh()->update($change));
                $this->fail('Closed period must be immutable.');
            } catch (LogicException) {
            }
        }
        $this->assertSame('member', $member->fresh()->function);
    }

    public function test_transition_must_move_forward_and_keep_the_relation_key(): void
    {
        $member = ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-01-01'));

        try {
            $this->because('backdated', fn () => $member->transition(RelationStatus::Suspended, $this->at('2025-12-01')));
            $this->fail('Transition before the start must be refused.');
        } catch (ValidityConflict) {
        }

        $this->expectException(LogicException::class);
        $this->because('move', fn () => $member->transition(RelationStatus::Active, $this->at('2026-02-01'), ['context_ref' => 'ORG-9']));
    }

    public function test_period_changes_require_a_reason_and_are_audited(): void
    {
        $member = ValidityProbe::startPeriod([...self::KEY, 'function' => 'member'], $this->at('2026-01-01'));

        try {
            $member->end($this->at('2026-02-01'));
            $this->fail('A reason is required to change a relation.');
        } catch (LogicException) {
        }
        $this->assertNull($member->fresh()->valid_to, 'Brak powodu wycofuje zmianę.');

        $this->because('moved to another branch', fn () => $member->end($this->at('2026-02-01')));

        $entry = AuditEntry::query()->where('action', 'validity_probe.updated')->sole();
        $this->assertSame('moved to another branch', $entry->reason);
        $this->assertSame('ORG-1', $entry->organization_id);
        $this->assertSame(['valid_to' => null], $entry->before_values);
    }
}
