<?php

namespace App\Http\Exceptions;

use App\Domain\Platform\Actions\RecordAccessDenial;
use App\Domain\Platform\Exceptions\AccessDenied;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Exception render hook (A5-14, E1.4): every denied HTTP operation — a 403, or an authorization
 * denial rendered as 404 to hide the record — is audited. Always returns null, so the standard
 * response is unchanged; an audit failure is logged and never turns a denial into a 500.
 */
final class AuditAccessDenials
{
    public function __invoke(HttpExceptionInterface $e, Request $request): null
    {
        $denial = $e->getPrevious() instanceof AuthorizationException ? $e->getPrevious() : null;
        if ($denial === null && $e->getStatusCode() !== 403) {
            return null;
        }

        $route = $request->route();
        $target = $route?->getName() ?? $request->method().' '.($route?->uri() ?? 'unmatched');
        $subject = $denial instanceof AccessDenied ? $denial : null;

        try {
            app(RecordAccessDenial::class)->handle(
                $subject->subjectType ?? 'route',
                $subject->subjectId ?? $target,
                $subject?->organizationId,
                array_filter([
                    'status' => $e->getStatusCode(),
                    'method' => $request->method(),
                    'route' => $target,
                    'ability' => $subject?->ability,
                ], fn ($value) => $value !== null),
            );
        } catch (Throwable $failure) {
            Log::critical('Access denial could not be audited.', ['exception' => $failure::class, 'message' => $failure->getMessage()]);
        }

        return null;
    }
}
