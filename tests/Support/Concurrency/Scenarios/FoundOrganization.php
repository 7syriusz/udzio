<?php

namespace Tests\Support\Concurrency\Scenarios;

use App\Domain\Organization\Actions\FoundOrganization as Found;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use Tests\Support\Concurrency\Scenario;

/** The founding form submitted by an account; returns the public ID of the founded organization. */
class FoundOrganization implements Scenario
{
    public function run(array $arguments): mixed
    {
        return app(ActorContext::class)->runAs(Actor::account((string) $arguments['account']),
            fn () => app(Found::class)->handle($arguments['name'], 'Założenie organizacji', $arguments['key'])->public_id);
    }
}
