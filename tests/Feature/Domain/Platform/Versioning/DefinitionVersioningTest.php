<?php

namespace Tests\Feature\Domain\Platform\Versioning;

use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Models\AuditEntry;
use App\Domain\Platform\Models\DefinitionVersion;
use App\Domain\Platform\Versioning\ContentHash;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Fixtures\DefinitionProbe;
use Tests\Fixtures\DefinitionResultProbe;
use Tests\TestCase;

class DefinitionVersioningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function definition(array $rules = ['points_per_win' => 3]): DefinitionProbe
    {
        return DefinitionProbe::create(['organization_ref' => 'ORG-1', 'name' => 'Scoring', 'rules' => $rules]);
    }

    private function because(string $reason, callable $operation): mixed
    {
        return $this->app->make(AuditReason::class)->because($reason, $operation(...));
    }

    public function test_result_points_to_the_exact_version_and_keeps_its_meaning_after_a_new_version(): void
    {
        $definition = $this->definition();
        $v1 = $definition->publishVersion();
        $result = DefinitionResultProbe::create(['definition_version_id' => $v1->id, 'score' => 6]);

        $this->because('league changed scoring', fn () => $definition->update(['rules' => ['points_per_win' => 2]]));
        $v2 = $definition->publishVersion();

        $this->assertSame([1, 2], [$v1->version, $v2->version]);
        $this->assertSame(['points_per_win' => 3], $result->fresh()->definitionVersion->content['rules']);
        $this->assertSame(['points_per_win' => 2], $definition->latestVersion()->content['rules']);
    }

    public function test_draft_changes_do_not_reach_published_versions(): void
    {
        $definition = $this->definition();
        $v1 = $definition->publishVersion();

        $this->because('draft edit', fn () => $definition->update(['name' => 'Scoring 2027']));

        $this->assertSame('Scoring', $v1->fresh()->content['name']);
        $this->assertCount(1, $definition->versions);
    }

    public function test_publishing_unchanged_content_does_not_create_a_new_version(): void
    {
        $definition = $this->definition(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]);
        $v1 = $definition->publishVersion();

        $this->assertTrue($v1->is($definition->publishVersion()));
        $this->assertSame(ContentHash::of(['rules' => ['a' => ['x' => 1, 'y' => 2], 'b' => 1], 'name' => 'Scoring']), $v1->content_hash);
        $this->assertNotSame(ContentHash::of(['list' => [1, 2]]), ContentHash::of(['list' => [2, 1]]), 'Kolejność listy ma znaczenie.');
    }

    public function test_versions_cannot_be_changed_or_removed_in_code_or_sql(): void
    {
        $version = $this->definition()->publishVersion();

        foreach ([fn () => $version->update(['content' => []]), fn () => $version->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Version must be immutable in code.');
            } catch (LogicException) {
            }
        }
        foreach ([
            fn () => DB::table('definition_versions')->where('id', $version->id)->update(['content' => '[]']),
            fn () => DB::table('definition_versions')->where('id', $version->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Version must be immutable in SQL.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Definition versions are immutable', $e->getMessage());
            }
        }
    }

    public function test_result_cannot_switch_to_another_version_or_point_to_a_foreign_definition_type(): void
    {
        $definition = $this->definition();
        $v1 = $definition->publishVersion();
        $this->because('change', fn () => $definition->update(['rules' => ['points_per_win' => 2]]));
        $v2 = $definition->publishVersion();
        $result = DefinitionResultProbe::create(['definition_version_id' => $v1->id, 'score' => 6]);

        try {
            $result->update(['definition_version_id' => $v2->id]);
            $this->fail('Result must keep its version.');
        } catch (LogicException) {
        }
        $this->assertSame($v1->id, $result->fresh()->definition_version_id);

        $foreign = DefinitionVersion::create([
            'public_id' => 'ignored', 'definition_type' => 'other_definition', 'definition_id' => 1, 'version' => 1,
            'content' => [], 'content_hash' => str_repeat('0', 64), 'published_by_type' => 'process', 'published_at' => now(),
        ]);
        $this->expectException(LogicException::class);
        DefinitionResultProbe::create(['definition_version_id' => $foreign->id, 'score' => 1]);
    }

    public function test_publication_is_attributed_and_audited(): void
    {
        $definition = $this->definition();

        $version = $this->app->make(ActorContext::class)->runAs(Actor::integration('importer'), fn () => $definition->publishVersion());

        $this->assertSame('importer', $version->published_by_id);
        $entry = AuditEntry::query()->where('action', 'definition_probe.version_published')->sole();
        $this->assertSame('ORG-1', $entry->organization_id);
        $this->assertSame(['version' => 1, 'content_hash' => $version->content_hash], $entry->after_values);
    }

    public function test_version_in_force_at_a_moment(): void
    {
        $definition = $this->definition();
        $this->travelTo('2026-01-01 10:00:00');
        $definition->publishVersion();
        $this->travelTo('2026-03-01 10:00:00');
        $this->because('change', fn () => $definition->update(['rules' => ['points_per_win' => 2]]));
        $definition->publishVersion();

        $this->assertNull($definition->versionAt(now()->setDate(2025, 12, 31)));
        $this->assertSame(1, $definition->versionAt(now()->setDate(2026, 2, 1))->version);
        $this->assertSame(2, $definition->versionAt(now()->setDate(2026, 3, 2))->version);
    }

    public function test_unsaved_draft_cannot_be_published(): void
    {
        $definition = $this->definition();
        $definition->name = 'Unsaved';

        $this->expectException(LogicException::class);
        $definition->publishVersion();
    }
}
