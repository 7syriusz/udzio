<?php

namespace Tests\Support\Concurrency\Scenarios;

use App\Domain\Platform\Enums\RelationStatus;
use Carbon\CarbonImmutable;
use Tests\Fixtures\ValidityProbe;
use Tests\Support\Concurrency\Scenario;

/** Opens a period of a ValidityProbe relation through HasValidityPeriod::startPeriod. */
class StartValidityPeriod implements Scenario
{
    public function run(array $arguments): mixed
    {
        return ValidityProbe::startPeriod(
            $arguments['attributes'],
            CarbonImmutable::parse($arguments['from'], 'UTC'),
            RelationStatus::Active,
        )->getKey();
    }
}
