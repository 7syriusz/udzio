<?php

namespace Tests\Support\Concurrency\Scenarios;

use App\Domain\Platform\Actions\RunIdempotently;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\Scenario;

/** Creates one idempotency_probes row through RunIdempotently, as the integration actor `race`. */
class CreateIdempotently implements Scenario
{
    public function run(array $arguments): mixed
    {
        $outcome = app(ActorContext::class)->runAs(Actor::integration('race'), fn () => app(RunIdempotently::class)->handle(
            'probe.create', $arguments['key'], ['label' => $arguments['label']],
            fn () => ['id' => DB::table('idempotency_probes')->insertGetId(['label' => $arguments['label']])],
        ));

        return ['value' => $outcome->value, 'replayed' => $outcome->replayed];
    }
}
