<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Trusts forwarding headers (scheme, host, client address) only from the proxies listed in `app.trusted_proxies`
 * (E3.10d). Empty by default: nothing is trusted. A local review tunnel (cloudflared on the same machine) sets
 * `TRUSTED_PROXIES=127.0.0.1`, so links, assets and signed URLs use the visitor's HTTPS address.
 */
class TrustConfiguredProxies extends TrustProxies
{
    /** @return array<int, string>|string|null */
    protected function proxies()
    {
        $configured = array_values(array_filter(array_map('trim', explode(',', (string) config('app.trusted_proxies')))));

        return $configured === [] ? null : $configured;
    }
}
