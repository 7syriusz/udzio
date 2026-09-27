<?php

namespace Tests\Support\Concurrency\Scenarios;

use App\Domain\Identity\Models\Person;
use App\Domain\Organization\Actions\AdmitMember as Admit;
use App\Domain\Organization\Models\Organization;
use Tests\Support\Concurrency\Scenario;

class AdmitMember implements Scenario
{
    public function run(array $arguments): mixed
    {
        return app(Admit::class)->handle(Person::findOrFail($arguments['person']), Organization::findOrFail($arguments['organization']), 'member', 'Concurrent admission')->id;
    }
}
