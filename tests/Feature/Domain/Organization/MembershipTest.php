<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Models\Person;
use App\Domain\Organization\Actions\AdmitMember;
use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\ChangeMembership;
use App\Domain\Organization\Actions\EndMembership;
use App\Domain\Organization\Actions\TransferMembership;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\RelationStatus;
use App\Domain\Platform\Exceptions\ValidityConflict;
use App\Domain\Platform\Models\AuditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\Support\RunsAsSystem;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use LazilyRefreshDatabase, RunsAsSystem;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
        $this->person = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Anna', 'family_name' => 'Nowak']);
    }

    private function admit(Organization $organization, string $function = 'member'): Membership
    {
        return $this->app->make(AdmitMember::class)->handle($this->person, $organization, $function, 'Uchwała o przyjęciu');
    }

    /** @return array{function: string, status: RelationStatus}|null */
    private function stateOn(string $moment, Organization $organization): ?array
    {
        $period = Membership::query()->where(['person_id' => $this->person->id, 'organization_id' => $organization->id])
            ->effectiveAt(CarbonImmutable::parse($moment, 'UTC'))->first();

        return $period === null ? null : ['function' => $period->function, 'status' => $period->status];
    }

    public function test_admission_opens_an_active_audited_membership(): void
    {
        $organization = Organization::factory()->create();

        $membership = $this->admit($organization, '  treasurer ');

        $this->assertSame(['treasurer', RelationStatus::Active], [$membership->function, $membership->status]);
        $this->assertNull($membership->valid_to);
        $entry = AuditEntry::query()->where('action', 'membership.created')->sole();
        $this->assertSame('Uchwała o przyjęciu', $entry->reason);
        $this->assertSame($organization->public_id, $entry->organization_id);
        $this->assertSame((string) $membership->id, $entry->subject_id);
    }

    public function test_suspension_resumption_and_function_change_keep_the_state_on_every_date(): void
    {
        $organization = Organization::factory()->create();
        $membership = $this->admit($organization);
        $change = $this->app->make(ChangeMembership::class);

        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'));
        $membership = $change->handle($membership, 'member', RelationStatus::Suspended, 'Zaległe składki');
        $this->travelTo(CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'));
        $membership = $change->handle($membership, 'member', RelationStatus::Active, 'Składki opłacone');
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'));
        $change->handle($membership, 'chair', RelationStatus::Active, 'Wybór na przewodniczącą');

        $this->assertNull($this->stateOn('2025-12-31 23:59:59', $organization));
        $this->assertSame(['function' => 'member', 'status' => RelationStatus::Active], $this->stateOn('2026-02-15', $organization));
        $this->assertSame(['function' => 'member', 'status' => RelationStatus::Suspended], $this->stateOn('2026-03-15', $organization));
        $this->assertSame(['function' => 'member', 'status' => RelationStatus::Active], $this->stateOn('2026-05-01', $organization));
        $this->assertSame(['function' => 'chair', 'status' => RelationStatus::Active], $this->stateOn('2026-06-01', $organization));
        $this->assertCount(4, $membership->history());
    }

    public function test_repeating_the_current_state_changes_nothing(): void
    {
        $membership = $this->admit(Organization::factory()->create());

        $same = $this->app->make(ChangeMembership::class)->handle($membership, 'member', RelationStatus::Active, 'Bez zmian');

        $this->assertTrue($same->is($membership));
        $this->assertCount(1, $membership->history());
    }

    public function test_transfer_moves_the_membership_between_units_with_a_link_to_its_origin(): void
    {
        [$branchA, $branchB] = Organization::factory()->count(2)->create()->all();
        $membership = $this->admit($branchA, 'secretary');
        $this->travelTo(CarbonImmutable::parse('2026-05-01 00:00:00', 'UTC'));

        $moved = $this->app->make(TransferMembership::class)->handle($membership, $branchB, 'Zmiana miejsca zamieszkania');

        $this->assertTrue($membership->fresh()->valid_to->equalTo($moved->valid_from));
        $this->assertSame([$branchB->id, 'secretary', $membership->id], [$moved->organization_id, $moved->function, $moved->transferred_from_id]);
        $this->assertSame('secretary', $this->stateOn('2026-04-30 23:59:59', $branchA)['function']);
        $this->assertNull($this->stateOn('2026-05-01', $branchA));
        $this->assertNotNull($this->stateOn('2026-05-01', $branchB));
    }

    public function test_transfer_of_a_suspended_membership_keeps_it_suspended(): void
    {
        [$branchA, $branchB] = Organization::factory()->count(2)->create()->all();
        $membership = $this->admit($branchA);
        $this->travel(1)->day();
        $suspended = $this->app->make(ChangeMembership::class)->handle($membership, 'member', RelationStatus::Suspended, 'Zawieszenie');
        $this->travel(1)->day();

        $moved = $this->app->make(TransferMembership::class)->handle($suspended, $branchB, 'Przeniesienie');

        $this->assertSame(RelationStatus::Suspended, $moved->status);
    }

    public function test_periods_in_one_organization_never_overlap_but_other_organizations_are_independent(): void
    {
        [$club, $school] = Organization::factory()->count(2)->create()->all();
        $this->admit($club);
        $this->admit($school);

        $this->expectException(ValidityConflict::class);
        $this->admit($club);
    }

    public function test_transfer_into_an_organization_with_an_open_membership_is_refused(): void
    {
        [$branchA, $branchB] = Organization::factory()->count(2)->create()->all();
        $membership = $this->admit($branchA);
        $this->admit($branchB);
        $this->travel(1)->day();

        try {
            $this->app->make(TransferMembership::class)->handle($membership, $branchB, 'Przeniesienie');
            $this->fail('Transfer must be refused.');
        } catch (ValidityConflict) {
        }
        $this->assertNull($membership->fresh()->valid_to, 'Nieudane przeniesienie nie zamyka członkostwa.');
    }

    public function test_ended_membership_stays_in_history_and_a_later_admission_opens_a_new_period(): void
    {
        $organization = Organization::factory()->create();
        $first = $this->admit($organization);
        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC'));
        $this->app->make(EndMembership::class)->handle($first, 'Rezygnacja');
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'));

        $second = $this->admit($organization);

        $this->assertNull($this->stateOn('2026-05-01', $organization));
        $this->assertSame([$first->id, $second->id], $second->history()->modelKeys());
    }

    public function test_stale_membership_cannot_be_changed_again(): void
    {
        $membership = $this->admit(Organization::factory()->create());
        $this->travel(1)->day();
        $this->app->make(EndMembership::class)->handle($membership, 'Rezygnacja');

        $this->expectException(ValidityConflict::class);
        $this->app->make(ChangeMembership::class)->handle($membership, 'chair', RelationStatus::Active, 'Na starym stanie');
    }

    public function test_archived_organization_accepts_no_admission_or_transfer(): void
    {
        [$active, $archived] = Organization::factory()->count(2)->create()->all();
        $this->asSystem(fn () => $this->app->make(ArchiveOrganization::class)->handle($archived, 'Likwidacja koła'));
        $membership = $this->admit($active);

        foreach ([fn () => $this->admit($archived), fn () => $this->app->make(TransferMembership::class)->handle($membership, $archived, 'x')] as $attempt) {
            try {
                $attempt();
                $this->fail('Archived organization must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('organization', $e->errors());
            }
        }
    }

    public function test_function_and_reason_are_required(): void
    {
        $organization = Organization::factory()->create();

        try {
            $this->admit($organization, '   ');
            $this->fail('Empty function must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('function', $e->errors());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(AdmitMember::class)->handle($this->person, $organization, 'member', ' ');
    }

    public function test_history_cannot_be_rewritten_or_deleted(): void
    {
        $membership = $this->admit(Organization::factory()->create());

        try {
            $this->app->make(AuditReason::class)->because('Poprawka', fn () => $membership->update(['function' => 'chair']));
            $this->fail('Periods are immutable.');
        } catch (LogicException) {
        }
        try {
            $membership->delete();
            $this->fail('Deleting history must be refused.');
        } catch (LogicException) {
        }

        $this->expectException(QueryException::class);
        DB::table('memberships')->where('id', $membership->id)->delete();
    }
}
