<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Concurrency\Race;
use Tests\Support\Concurrency\Scenarios\TakeUnitWithLock;
use Tests\Support\Concurrency\Scenarios\TakeUnitWithoutLock;
use Tests\TestCase;

#[Group('concurrency')]
class RaceHarnessTest extends TestCase
{
    use DatabaseTruncation;

    private int $probeId;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('concurrency_probes');
        Schema::create('concurrency_probes', function (Blueprint $table) {
            $table->id();
            $table->integer('quantity');
        });
        $this->probeId = DB::table('concurrency_probes')->insertGetId(['quantity' => 1]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('concurrency_probes');
        parent::tearDown();
    }

    public function test_locked_operation_gives_the_last_unit_to_exactly_one_of_two_real_parallel_workers(): void
    {
        $race = Race::run('SELECT id FROM concurrency_probes WHERE id = ? FOR UPDATE', [$this->probeId], [
            [TakeUnitWithLock::class, ['id' => $this->probeId]],
            [TakeUnitWithLock::class, ['id' => $this->probeId]],
        ]);

        $this->assertSame(2, $race['blocked'], 'Oba procesy musiały czekać na tę samą blokadę: '.json_encode($race['results'], JSON_UNESCAPED_UNICODE));
        $outcomes = array_map(fn ($r) => $r['ok'], $race['results']);
        sort($outcomes);
        $this->assertSame([false, true], $outcomes, json_encode($race['results'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, (int) DB::table('concurrency_probes')->where('id', $this->probeId)->value('quantity'));
    }

    public function test_harness_detects_a_lost_update_in_an_unlocked_operation(): void
    {
        $race = Race::run('SELECT id FROM concurrency_probes WHERE id = ? FOR UPDATE', [$this->probeId], [
            [TakeUnitWithoutLock::class, ['id' => $this->probeId]],
            [TakeUnitWithoutLock::class, ['id' => $this->probeId]],
        ]);

        // Both workers read quantity=1 before blocking on the UPDATE, so both "succeed":
        // two units handed out from a stock of one. The harness must make this visible.
        $this->assertSame(2, $race['blocked'], json_encode($race['results'], JSON_UNESCAPED_UNICODE));
        $this->assertSame([true, true], array_map(fn ($r) => $r['ok'], $race['results']), json_encode($race['results'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, (int) DB::table('concurrency_probes')->where('id', $this->probeId)->value('quantity'));
    }

    public function test_worker_refuses_classes_that_are_not_test_scenarios(): void
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('tests/Support/Concurrency/worker.php')).' '.escapeshellarg('App\\Models\\User').' {} 2>&1', $output, $code);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('nie jest scenariuszem testowym', implode("\n", $output));
    }
}
