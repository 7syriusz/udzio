<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActor
{
    public function __construct(private readonly ActorContext $context) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->context->within(function () use ($request): Actor {
            $identifier = $request->user()?->getAuthIdentifier();

            return $identifier === null ? Actor::anonymous() : Actor::account((string) $identifier);
        }, fn (): Response => $next($request));
    }
}
