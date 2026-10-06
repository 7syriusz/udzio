<?php

namespace Tests\Support;

use App\Domain\Organization\Access\SystemAuthority;
use Closure;
use Tests\Fixtures\AnyScopeTestPurpose;

/**
 * Test setup through the real, centrally authorized actions (E3.10a): the operation runs as a technical
 * process under SystemAuthority with the test-only purpose covering every unit.
 */
trait RunsAsSystem
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    protected function asSystem(Closure $operation): mixed
    {
        return $this->app->make(SystemAuthority::class)->run(new AnyScopeTestPurpose, $operation);
    }
}
