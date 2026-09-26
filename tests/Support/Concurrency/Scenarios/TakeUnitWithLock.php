<?php

namespace Tests\Support\Concurrency\Scenarios;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Concurrency\Scenario;

/** Correct pattern: lock the row, check the remaining quantity, decrement. */
class TakeUnitWithLock implements Scenario
{
    public function run(array $arguments): mixed
    {
        return DB::transaction(function () use ($arguments) {
            $row = DB::table('concurrency_probes')->where('id', $arguments['id'])->lockForUpdate()->first();
            if ($row->quantity < 1) {
                throw new RuntimeException('Brak dostępnych jednostek.');
            }
            DB::table('concurrency_probes')->where('id', $row->id)->update(['quantity' => $row->quantity - 1]);

            return $row->quantity - 1;
        });
    }
}
