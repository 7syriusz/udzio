<?php

namespace Tests\Support\Concurrency\Scenarios;

use App\Domain\Organization\Actions\MoveOrganization as Move;
use App\Domain\Organization\Models\Organization;
use Tests\Support\Concurrency\Scenario;

class MoveOrganization implements Scenario
{
    public function run(array $arguments): mixed
    {
        return app(Move::class)->handle(Organization::findOrFail($arguments['child']), Organization::findOrFail($arguments['parent']), 'Concurrent move')?->id;
    }
}
