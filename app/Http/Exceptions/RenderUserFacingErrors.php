<?php

namespace App\Http\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** JSON errors carry a translated message, never a raw technical one (E3.6b); HTML uses views/errors. */
final class RenderUserFacingErrors
{
    public function __invoke(HttpExceptionInterface $e, Request $request): ?JsonResponse
    {
        if (config('app.debug') || ! ($request->is('api/*') || $request->expectsJson())) {
            return null;
        }

        return new JsonResponse(['message' => UserFacingMessage::for($e)], $e->getStatusCode(), $e->getHeaders());
    }
}
