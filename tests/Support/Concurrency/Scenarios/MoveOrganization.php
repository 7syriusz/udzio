<?php

namespace Tests\Support\Concurrency\Scenarios;

use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Actions\MoveOrganization as Move;
use App\Domain\Organization\Models\Organization;
use Tests\Fixtures\AnyScopeTestPurpose;
use Tests\Support\Concurrency\Scenario;

class MoveOrganization implements Scenario
{
    public function run(array $arguments): mixed
    {
        // Structure changes are authorized centrally (E3.10a): the worker acts under the test-only system purpose.
        return app(SystemAuthority::class)->run(new AnyScopeTestPurpose, fn () => app(Move::class)->handle(Organization::findOrFail($arguments['child']), Organization::findOrFail($arguments['parent']), 'Concurrent move')?->id);
    }
}
