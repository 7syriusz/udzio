<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Localization\LocaleResolver;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/** Sets the language of the request from LocaleResolver — the only place that chooses it (E3.6b). */
class SetLocale
{
    public function __construct(private readonly LocaleResolver $locales) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();
        App::setLocale($this->locales->resolve(
            $request->hasSession() ? $request->session() : null,
            $account instanceof User ? $account : null,
        ));

        return $next($request);
    }
}
