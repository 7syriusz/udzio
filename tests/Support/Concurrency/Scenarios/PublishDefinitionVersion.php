<?php

namespace Tests\Support\Concurrency\Scenarios;

use Tests\Fixtures\DefinitionProbe;
use Tests\Support\Concurrency\Scenario;

/** Publishes the current snapshot of a DefinitionProbe through HasVersions::publishVersion. */
class PublishDefinitionVersion implements Scenario
{
    public function run(array $arguments): mixed
    {
        $version = DefinitionProbe::query()->findOrFail($arguments['id'])->publishVersion();

        return ['id' => $version->id, 'version' => $version->version];
    }
}
