<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/** Trust forwarded headers only from proxies listed in TRUSTED_PROXIES. */
class TrustConfiguredProxies extends TrustProxies
{
    protected function proxies()
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) config('legacy.trusted_proxies')))));

        return $list ?: null;
    }
}
