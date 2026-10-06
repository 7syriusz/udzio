<?php

namespace Tests\Support\Concurrency\Scenarios;

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Concurrency\Scenario;

/** Runs the installation command in its own process; returns its exit code. */
class InstallPlatform implements Scenario
{
    public function run(array $arguments): mixed
    {
        return Artisan::call('platform:install-administrator', ['email' => $arguments['email'], '--given-name' => 'Ada', '--family-name' => 'Nowak']);
    }
}
