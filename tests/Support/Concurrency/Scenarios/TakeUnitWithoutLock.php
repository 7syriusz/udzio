<?php

namespace Tests\Support\Concurrency\Scenarios;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Concurrency\Scenario;

/**
 * Deliberately broken pattern (control case): plain read, check in PHP, then write the computed
 * value. Under contention both workers read the same value and one update is lost.
 */
class TakeUnitWithoutLock implements Scenario
{
    public function run(array $arguments): mixed
    {
        return DB::transaction(function () use ($arguments) {
            $row = DB::table('concurrency_probes')->where('id', $arguments['id'])->first();
            if ($row->quantity < 1) {
                throw new RuntimeException('Brak dostępnych jednostek.');
            }
            DB::table('concurrency_probes')->where('id', $row->id)->update(['quantity' => $row->quantity - 1]);

            return $row->quantity - 1;
        });
    }
}
