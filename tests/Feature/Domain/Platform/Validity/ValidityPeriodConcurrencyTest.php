<?php

namespace Tests\Feature\Domain\Platform\Validity;

use App\Domain\Platform\Exceptions\ValidityConflict;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Concurrency\Race;
use Tests\Support\Concurrency\Scenarios\StartValidityPeriod;
use Tests\TestCase;

#[Group('concurrency')]
class ValidityPeriodConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** Committed rows must not leak into transaction-based tests that run afterwards. */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_two_parallel_starts_of_the_same_relation_open_exactly_one_period(): void
    {
        $key = ['person_ref' => 'P-1', 'context_ref' => 'ORG-1'];
        $worker = [StartValidityPeriod::class, ['attributes' => [...$key, 'function' => 'member'], 'from' => '2026-01-01 00:00:00']];

        // Gate: an uncommitted open period for the same relation, undone after both workers wait on it.
        $race = Race::run(
            "INSERT INTO validity_probes (person_ref, context_ref, `function`, status, valid_from, created_at, updated_at)
             VALUES (?, ?, 'gate', 'active', '2025-01-01 00:00:00', NOW(6), NOW(6))",
            [$key['person_ref'], $key['context_ref']],
            [$worker, $worker],
            releaseByRollback: true,
        );

        $results = json_encode($race['results'], JSON_UNESCAPED_UNICODE);
        $this->assertSame(2, $race['blocked'], 'Oba procesy musiały czekać na okres bramki: '.$results);
        $outcomes = array_map(fn ($r) => $r['ok'] ? 'ok' : $r['error'], $race['results']);
        sort($outcomes);
        $this->assertSame([ValidityConflict::class, 'ok'], $outcomes, $results);
        $this->assertSame(1, DB::table('validity_probes')->where($key)->count());
        $this->assertSame(1, DB::table('validity_probes')->where($key)->whereNull('valid_to')->count());
    }
}
