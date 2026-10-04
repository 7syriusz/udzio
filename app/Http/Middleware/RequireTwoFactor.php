<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `mfa`: only accounts with confirmed two-factor authentication pass (A5 §12: MFA for
 * administrators). Applied to administrative routes from E3. Others get 403 with a hint (audited as a denial).
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->hasConfirmedTwoFactor()) {
            abort(403, 'access.mfa_required');
        }

        return $next($request);
    }
}
