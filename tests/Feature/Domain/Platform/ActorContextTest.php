<?php

namespace Tests\Feature\Domain\Platform;

use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\ActorType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ActorContextTest extends TestCase
{
    public function test_nested_explicit_actor_takes_precedence_and_restores_after_exception(): void
    {
        $context = new ActorContext(Actor::anonymous());
        $account = Actor::account('17');
        $integration = Actor::integration('payment-webhook');

        $result = $context->runAs($account, function () use ($context, $account, $integration): string {
            try {
                $context->runAs($integration, function () use ($context, $integration): void {
                    $this->assertSame($integration, $context->current());
                    throw new RuntimeException('failed operation');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('failed operation', $exception->getMessage());
            }
            $this->assertSame($account, $context->current());

            return 'completed';
        });

        $this->assertSame('completed', $result);
        $this->assertSame(['type' => 'anonymous', 'identifier' => null], $context->current()->toArray());
    }

    #[DataProvider('invalidActors')]
    public function test_rejects_invalid_actor_identity(ActorType $type, ?string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Actor($type, $identifier);
    }

    public static function invalidActors(): array
    {
        return [
            'anonymous with identity' => [ActorType::Anonymous, '17'],
            'account without identity' => [ActorType::Account, null],
            'integration without identity' => [ActorType::Integration, ''],
            'blank process' => [ActorType::Process, '   '],
        ];
    }

    public function test_next_worker_scope_has_no_previous_explicit_actor(): void
    {
        $context = $this->app->make(ActorContext::class);
        $context->enter('old-job', Actor::integration('old-integration'));

        $this->app->forgetScopedInstances();

        $this->assertSame(['type' => 'process', 'identifier' => 'application'], $this->app->make(ActorContext::class)->current()->toArray());
    }
}
