<?php

namespace Tests\Support\Concurrency\Scenarios;

use App\Domain\Organization\Actions\TransferMembership as Transfer;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use Tests\Support\Concurrency\Scenario;

class TransferMembership implements Scenario
{
    public function run(array $arguments): mixed
    {
        return app(Transfer::class)->handle(Membership::findOrFail($arguments['membership']), Organization::findOrFail($arguments['destination']), 'Concurrent transfer')->id;
    }
}
