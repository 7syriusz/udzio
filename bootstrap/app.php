<?php

use App\Http\Exceptions\AuditAccessDenials;
use App\Http\Middleware\ResolveActor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ResolveActor::class);
        // Ends sessions whose password hash is outdated (logout of other devices, password reset).
        $middleware->web(append: AuthenticateSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new AuditAccessDenials);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
