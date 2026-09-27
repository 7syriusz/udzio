<?php

namespace App\Providers;

use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditReason::class);
        $this->app->scoped(OperationCorrelation::class);
        $this->app->scoped(ActorContext::class, fn (Application $app): ActorContext => new ActorContext(
            $app->runningInConsole() ? Actor::process('application') : Actor::anonymous(),
        ));
    }

    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->app->make(ActorContext::class)->enter(
                'command:'.spl_object_id($event->input), Actor::process('command:'.$event->command),
            );
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            $this->app->make(ActorContext::class)->leave('command:'.spl_object_id($event->input));
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->app->make(ActorContext::class)->enter(
                'job:'.spl_object_id($event->job), Actor::process('job:'.$event->job->resolveName()),
            );
        });

        Event::listen(JobAttempted::class, function (JobAttempted $event): void {
            $this->app->make(ActorContext::class)->leave('job:'.spl_object_id($event->job));
        });
    }
}
