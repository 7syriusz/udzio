<?php

namespace Tests\Feature\Providers;

use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\ActorProbe;
use Tests\Fixtures\CaptureActorJob;
use Tests\TestCase;

class PlatformServiceProviderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_nested_commands_restore_calling_actor_even_after_failure(): void
    {
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
        $context = $this->app->make(ActorContext::class);
        $observations = [];
        Artisan::command('actor:inner', function () use ($context, &$observations): void {
            $observations['inner'] = $context->current()->toArray();
            throw new RuntimeException('command failed');
        });
        Artisan::command('actor:outer', function () use ($context, &$observations): void {
            try {
                Artisan::call('actor:inner');
            } catch (RuntimeException) {
                $observations['outer'] = $context->current()->toArray();
            }
        });

        Artisan::call('actor:outer');

        $this->assertSame([
            'inner' => ['type' => 'process', 'identifier' => 'command:actor:inner'],
            'outer' => ['type' => 'process', 'identifier' => 'command:actor:outer'],
        ], $observations);
        $this->assertSame('application', $context->current()->identifier);
    }

    public function test_sync_jobs_use_process_actor_and_restore_caller_after_failure(): void
    {
        $probe = new ActorProbe;
        $this->app->instance(ActorProbe::class, $probe);
        $context = $this->app->make(ActorContext::class);
        $caller = Actor::account('17');

        $context->runAs($caller, function () use ($context, $caller): void {
            try {
                Queue::connection('sync')->push(new CaptureActorJob('failed', fail: true));
            } catch (RuntimeException $exception) {
                $this->assertSame('job failed', $exception->getMessage());
            }
            $this->assertSame($caller, $context->current());
            Queue::connection('sync')->push(new CaptureActorJob('next'));
            $this->assertSame($caller, $context->current());
        });

        $this->assertSame([
            'failed' => ['type' => 'process', 'identifier' => 'job:'.CaptureActorJob::class],
            'next' => ['type' => 'process', 'identifier' => 'job:'.CaptureActorJob::class],
        ], $probe->observations);
    }

    public function test_database_queue_resolves_actor_when_job_is_executed_by_worker(): void
    {
        $probe = new ActorProbe;
        $this->app->instance(ActorProbe::class, $probe);
        Queue::connection('database')->pushOn('actor-test', new CaptureActorJob('database'));

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'actor-test', '--tries' => 1])->assertExitCode(0);

        $this->assertSame([
            'database' => ['type' => 'process', 'identifier' => 'job:'.CaptureActorJob::class],
        ], $probe->observations);
        $this->assertDatabaseCount('jobs', 0);
    }
}
