<?php

namespace Tests\Feature\Http\Middleware;

use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ResolveActorTest extends TestCase
{
    /** Denied requests are audited since E1.4, so this test now writes to the database. */
    use LazilyRefreshDatabase;

    public function test_http_ignores_claimed_actor_from_headers_and_input(): void
    {
        Route::get('/actor-probe', fn (ActorContext $context): array => $context->current()->toArray());

        $this->withHeaders(['X-Actor-Id' => '17', 'X-Actor-Type' => 'integration'])
            ->getJson('/actor-probe?actor_id=17')
            ->assertExactJson(['type' => 'anonymous', 'identifier' => null]);
    }

    public function test_http_resolves_account_after_authentication_and_respects_explicit_integration(): void
    {
        $user = User::factory()->make(['id' => 17]);
        Route::get('/actor-probe', function (ActorContext $context) use ($user): array {
            $before = $context->current()->toArray();
            Auth::setUser($user);
            $authenticated = $context->current()->toArray();
            $integration = $context->runAs(Actor::integration('verified-webhook'), fn (): array => $context->current()->toArray());

            return compact('before', 'authenticated', 'integration') + ['restored' => $context->current()->toArray()];
        });

        $this->getJson('/actor-probe')->assertExactJson([
            'before' => ['type' => 'anonymous', 'identifier' => null],
            'authenticated' => ['type' => 'account', 'identifier' => '17'],
            'integration' => ['type' => 'integration', 'identifier' => 'verified-webhook'],
            'restored' => ['type' => 'account', 'identifier' => '17'],
        ]);
    }

    public function test_authenticated_request_does_not_leak_into_next_anonymous_request(): void
    {
        Route::get('/actor-probe', fn (ActorContext $context): array => $context->current()->toArray());
        $user = User::factory()->make(['id' => 17]);

        $this->actingAs($user)->getJson('/actor-probe')->assertExactJson(['type' => 'account', 'identifier' => '17']);
        Auth::forgetGuards();
        $this->getJson('/actor-probe')->assertExactJson(['type' => 'anonymous', 'identifier' => null]);
    }

    public function test_http_restores_outer_context_after_forbidden_response(): void
    {
        $context = $this->app->make(ActorContext::class);
        Route::get('/actor-denied', function (): void {
            abort(403);
        });
        $outer = Actor::process('test-runner');

        $context->runAs($outer, function () use ($context, $outer): void {
            $this->getJson('/actor-denied')->assertForbidden();
            $this->assertSame($outer, $context->current());
        });
    }
}
