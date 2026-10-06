<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\OrganizationHierarchy;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\Support\RunsAsSystem;
use Tests\TestCase;

class OrganizationHierarchyTest extends TestCase
{
    use LazilyRefreshDatabase, RunsAsSystem;

    public function test_deep_hierarchy_uses_the_same_organization_model_and_has_ordered_queries(): void
    {
        $this->freezeTime();
        $nodes = Organization::factory()->count(12)->create();
        for ($i = 1; $i < $nodes->count(); $i++) {
            $this->asSystem(fn () => $this->app->make(MoveOrganization::class)->handle($nodes[$i], $nodes[$i - 1], 'Struktura'));
        }
        $hierarchy = $this->app->make(OrganizationHierarchy::class);

        $this->assertSame($nodes->slice(1)->values()->modelKeys(), $hierarchy->descendantsAt($nodes[0], now())->modelKeys());
        $this->assertSame($nodes->slice(0, 11)->reverse()->values()->modelKeys(), $hierarchy->ancestorsAt($nodes[11], now())->modelKeys());
        $this->assertCount(0, $hierarchy->ancestorsAt($nodes[0], now()));
        $this->assertCount(0, $hierarchy->descendantsAt($nodes[11], now()));
    }

    public function test_move_preserves_subtree_and_history_at_the_exact_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00:00.123456', 'UTC'));
        [$first, $second, $unit, $child] = Organization::factory()->count(4)->create()->all();
        $move = $this->app->make(MoveOrganization::class);
        $old = $this->asSystem(fn () => $move->handle($unit, $first, 'Pierwszy przydział'));
        $this->asSystem(fn () => $move->handle($child, $unit, 'Dział'));
        $before = now()->toImmutable();
        $this->travelTo($before->addMicrosecond());

        $new = $this->asSystem(fn () => $move->handle($unit, $second, 'Reorganizacja'));

        $hierarchy = $this->app->make(OrganizationHierarchy::class);
        $this->assertSame([$unit->id, $first->id], $hierarchy->ancestorsAt($child, $before)->modelKeys());
        $this->assertSame([$unit->id, $second->id], $hierarchy->ancestorsAt($child, now())->modelKeys());
        $this->assertSame([], $hierarchy->descendantsAt($first, now())->modelKeys());
        $this->assertSame([$unit->id, $child->id], $hierarchy->descendantsAt($second, now())->modelKeys());
        $this->assertTrue($old->fresh()->valid_to->equalTo($new->valid_from));
        $this->assertNotSame($old->public_id, $new->public_id);
        $entries = AuditEntry::query()->where('subject_type', 'organization_parent')->where('reason', 'Reorganizacja')->get();
        $this->assertCount(2, $entries);
        $this->assertSame([$unit->public_id], $entries->pluck('organization_id')->unique()->values()->all());
    }

    public function test_detaching_makes_a_root_without_losing_past_structure(): void
    {
        $this->freezeTime();
        [$root, $unit] = Organization::factory()->count(2)->create()->all();
        $move = $this->app->make(MoveOrganization::class);
        $this->asSystem(fn () => $move->handle($unit, $root, 'Przyłączenie'));
        $before = now()->toImmutable();
        $this->travel(1)->seconds();

        $this->assertNull($this->asSystem(fn () => $move->handle($unit, null, 'Usamodzielnienie')));
        $hierarchy = $this->app->make(OrganizationHierarchy::class);
        $this->assertSame([], $hierarchy->ancestorsAt($unit, now())->modelKeys());
        $this->assertSame([$root->id], $hierarchy->ancestorsAt($unit, $before)->modelKeys());
        $this->assertDatabaseCount('organization_parents', 1);
    }

    public function test_self_and_indirect_cycles_are_rejected_without_writes(): void
    {
        [$root, $unit, $child] = Organization::factory()->count(3)->create()->all();
        $move = $this->app->make(MoveOrganization::class);
        $this->asSystem(fn () => $move->handle($unit, $root, 'Oddział'));
        $this->asSystem(fn () => $move->handle($child, $unit, 'Dział'));
        $count = AuditEntry::query()->where('action', '!=', 'access.granted')->count();

        foreach ([$root, $child] as $parent) {
            try {
                $this->asSystem(fn () => $move->handle($root, $parent, 'Nieprawidłowe przeniesienie'));
                $this->fail('Cycle accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent', $exception->errors());
            }
        }

        $this->assertDatabaseCount('organization_parents', 2);
        $this->assertSame($count, AuditEntry::query()->where('action', '!=', 'access.granted')->count(), 'Wpisy zmian (bez decyzji o dostępie).');
    }

    public function test_repeating_the_same_assignment_or_detachment_does_not_create_history(): void
    {
        [$root, $unit] = Organization::factory()->count(2)->create()->all();
        $move = $this->app->make(MoveOrganization::class);
        $this->assertNull($this->asSystem(fn () => $move->handle($unit, null, 'Już korzeń')));
        $period = $this->asSystem(fn () => $move->handle($unit, $root, 'Przyłączenie'));

        $this->assertSame($period->id, $this->asSystem(fn () => $move->handle($unit, $root, 'Ponowienie'))->id);
        $this->assertDatabaseCount('organization_parents', 1);
        $this->assertSame(3, AuditEntry::query()->where('action', '!=', 'access.granted')->count(), 'Wpisy zmian (bez decyzji o dostępie).');
    }

    public function test_archived_units_and_parents_cannot_be_assigned_using_stale_models(): void
    {
        [$active, $archived] = Organization::factory()->count(2)->create()->all();
        $this->asSystem(fn () => $this->app->make(ArchiveOrganization::class)->handle($archived, 'Archiwizacja'));
        foreach ([[$active, $archived], [$archived, $active]] as [$child, $parent]) {
            try {
                $this->asSystem(fn () => $this->app->make(MoveOrganization::class)->handle($child, $parent, 'Przeniesienie'));
                $this->fail('Archived organization accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('organization', $exception->errors());
            }
        }
        $this->assertDatabaseCount('organization_parents', 0);
    }

    public function test_move_requires_a_reason(): void
    {
        [$root, $unit] = Organization::factory()->count(2)->create()->all();
        try {
            $this->asSystem(fn () => $this->app->make(MoveOrganization::class)->handle($unit, $root, ' '));
            $this->fail('Reason omitted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Audit reason', $exception->getMessage());
        }
        $this->assertDatabaseCount('organization_parents', 0);
    }

    public function test_failed_audit_of_new_assignment_rolls_back_closing_previous_parent(): void
    {
        $this->freezeTime();
        [$first, $second, $unit] = Organization::factory()->count(3)->create()->all();
        $move = $this->app->make(MoveOrganization::class);
        $old = $this->asSystem(fn () => $move->handle($unit, $first, 'Przyłączenie'));
        $this->travel(1)->seconds();
        $auditCount = AuditEntry::query()->where('action', '!=', 'access.granted')->count();
        Event::listen('eloquent.creating: '.AuditEntry::class, function (AuditEntry $entry): void {
            if ($entry->action === 'organization_parent.created') {
                throw new RuntimeException('Audit unavailable');
            }
        });

        try {
            $this->asSystem(fn () => $move->handle($unit, $second, 'Przeniesienie'));
            $this->fail('Move completed without audit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertNull($old->fresh()->valid_to);
        $this->assertDatabaseCount('organization_parents', 1);
        $this->assertSame($auditCount, AuditEntry::query()->where('action', '!=', 'access.granted')->count(), 'Wpisy zmian (bez decyzji o dostępie).');
    }

    public function test_existing_assignment_cannot_be_rewritten_or_deleted(): void
    {
        [$first, $second, $unit] = Organization::factory()->count(3)->create()->all();
        $period = $this->asSystem(fn () => $this->app->make(MoveOrganization::class)->handle($unit, $first, 'Struktura'));
        try {
            $this->app->make(AuditReason::class)->because('Nadpisanie', fn () => $period->forceFill(['parent_id' => $second->id])->save());
            $this->fail('History overwritten.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot be rewritten', $exception->getMessage());
        }
        $this->assertSame($first->id, $period->fresh()->parent_id);
        $this->expectException(LogicException::class);
        $period->delete();
    }
}
