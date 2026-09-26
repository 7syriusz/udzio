<?php

namespace Tests\Support\Concurrency;

/**
 * An operation executed by a separate worker process (its own PHP process and its own MySQL
 * connection) during a concurrency test. Implementations live in the Tests\ namespace only.
 */
interface Scenario
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function run(array $arguments): mixed;
}
