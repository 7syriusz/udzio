<?php

namespace Tests\Feature\Domain\Platform\Versioning;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\DefinitionProbe;
use Tests\Support\Concurrency\Race;
use Tests\Support\Concurrency\Scenarios\PublishDefinitionVersion;
use Tests\TestCase;

#[Group('concurrency')]
class DefinitionVersionConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** Committed rows must not leak into transaction-based tests that run afterwards. */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_parallel_publications_of_the_same_content_create_one_version(): void
    {
        $definition = DefinitionProbe::create(['organization_ref' => 'ORG-1', 'name' => 'Scoring', 'rules' => ['points_per_win' => 3]]);
        $worker = [PublishDefinitionVersion::class, ['id' => $definition->id]];

        $race = Race::run('SELECT id FROM definition_probes WHERE id = ? FOR UPDATE', [$definition->id], [$worker, $worker]);

        $results = json_encode($race['results'], JSON_UNESCAPED_UNICODE);
        $this->assertSame(2, $race['blocked'], 'Oba procesy musiały czekać na blokadę definicji: '.$results);
        $this->assertSame([true, true], array_column($race['results'], 'ok'), $results);
        $this->assertSame([1, 1], array_column(array_column($race['results'], 'result'), 'version'), $results);
        $this->assertSame(1, DB::table('definition_versions')->count());
    }
}
