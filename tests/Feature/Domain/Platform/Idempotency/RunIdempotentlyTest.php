<?php

namespace Tests\Feature\Domain\Platform\Idempotency;

use App\Domain\Platform\Actions\RunIdempotently;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Exceptions\IdempotencyConflict;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class RunIdempotentlyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY = '01J9ZK8Q4Z7Y0V6C8T2B3N4M5P';

    private function create(string $label, string $key = self::KEY, string $scope = 'probe.create')
    {
        return $this->app->make(RunIdempotently::class)->handle($scope, $key, ['label' => $label],
            fn () => ['id' => DB::table('idempotency_probes')->insertGetId(['label' => $label])]);
    }

    public function test_retry_with_the_same_key_and_content_returns_the_first_result_without_running_again(): void
    {
        $first = $this->create('Anna');
        $retry = $this->create('Anna');

        $this->assertFalse($first->replayed);
        $this->assertTrue($retry->replayed);
        $this->assertSame($first->value, $retry->value);
        $this->assertSame(1, DB::table('idempotency_probes')->count());
    }

    public function test_same_key_with_different_content_is_refused(): void
    {
        $this->create('Anna');

        $this->expectException(IdempotencyConflict::class);
        $this->create('Barbara');
    }

    public function test_content_comparison_ignores_key_order(): void
    {
        $run = $this->app->make(RunIdempotently::class);
        $run->handle('probe.create', self::KEY, ['a' => 1, 'b' => 2], fn () => 'done');

        $this->assertTrue($run->handle('probe.create', self::KEY, ['b' => 2, 'a' => 1], fn () => 'again')->replayed);
    }

    public function test_failed_operation_releases_the_key(): void
    {
        $run = $this->app->make(RunIdempotently::class);
        try {
            $run->handle('probe.create', self::KEY, ['label' => 'Anna'], function () {
                DB::table('idempotency_probes')->insert(['label' => 'Anna']);
                throw new RuntimeException('payment gateway down');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('idempotency_probes')->count());
        $this->assertFalse($this->create('Anna')->replayed, 'Po błędzie ponowienie wykonuje operację.');
    }

    public function test_keys_are_separate_per_actor_and_per_operation(): void
    {
        $context = $this->app->make(ActorContext::class);
        $context->runAs(Actor::account('1'), fn () => $this->create('Anna'));
        $other = $context->runAs(Actor::account('2'), fn () => $this->create('Anna'));
        $otherOperation = $context->runAs(Actor::account('1'), fn () => $this->create('Anna', scope: 'probe.other'));

        $this->assertFalse($other->replayed, 'Klucz innego wykonawcy nie ujawnia cudzego wyniku.');
        $this->assertFalse($otherOperation->replayed);
        $this->assertSame(3, DB::table('idempotency_probes')->count());
    }

    public function test_key_format_is_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create('Anna', key: 'short');
    }
}
