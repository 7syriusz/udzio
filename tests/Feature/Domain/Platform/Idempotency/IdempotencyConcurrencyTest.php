<?php

namespace Tests\Feature\Domain\Platform\Idempotency;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Concurrency\Race;
use Tests\Support\Concurrency\Scenarios\CreateIdempotently;
use Tests\TestCase;

#[Group('concurrency')]
class IdempotencyConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** Committed rows must not leak into transaction-based tests that run afterwards. */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_two_parallel_requests_with_the_same_key_run_the_operation_once(): void
    {
        $key = '01J9ZK8Q4Z7Y0V6C8T2B3N4M5P';
        $worker = [CreateIdempotently::class, ['key' => $key, 'label' => 'Anna']];
        $owner = 'integration:race';

        // Gate: an uncommitted key row; both workers wait on the unique index, then the gate is undone.
        $race = Race::run(
            "INSERT INTO idempotency_keys (scope, owner, idempotency_key, request_hash, created_at) VALUES ('probe.create', ?, ?, ?, NOW(6))",
            [$owner, $key, str_repeat('0', 64)],
            [$worker, $worker],
            releaseByRollback: true,
        );

        $results = json_encode($race['results'], JSON_UNESCAPED_UNICODE);
        $this->assertSame(2, $race['blocked'], 'Oba procesy musiały czekać na ten sam klucz: '.$results);
        $this->assertSame([true, true], array_column($race['results'], 'ok'), $results);
        $outcomes = array_column($race['results'], 'result');
        $this->assertEqualsCanonicalizing([false, true], array_column($outcomes, 'replayed'), $results);
        $this->assertSame($outcomes[0]['value'], $outcomes[1]['value']);
        $this->assertSame(1, DB::table('idempotency_probes')->count());
    }
}
