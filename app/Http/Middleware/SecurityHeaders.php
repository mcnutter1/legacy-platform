<?php

namespace App\Http\Middleware;

use App\Support\Correlation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Correlation::set((string) $request->header('X-Correlation-Id'));
        /** @var Response $response */
        $response = $next($request);

        $login = parse_url((string) config('legacy.auth.login_base'));
        $loginOrigin = ($login['scheme'] ?? 'https').'://'.($login['host'] ?? 'login.mcnutt.cloud');
        $dev = app()->environment('local') && is_file(public_path('hot'));
        $devOrigin = $dev ? ' '.trim((string) @file_get_contents(public_path('hot'))) : '';
        $devWs = $dev ? ' '.preg_replace('#^http#', 'ws', trim((string) @file_get_contents(public_path('hot')))) : '';

        $h = $response->headers;
        $h->set('X-Correlation-Id', Correlation::id());
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        $h->set('Cross-Origin-Resource-Policy', 'same-origin');
        $h->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
        if (! $h->has('Referrer-Policy')) {
            $h->set('Referrer-Policy', 'no-referrer');
        }
        if (! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'".$devOrigin,
                "style-src 'self'".($dev ? " 'unsafe-inline'".$devOrigin : ''),
                "img-src 'self' data: blob:",
                "font-src 'self'",
                "connect-src 'self'".$devOrigin.$devWs,
                "frame-src 'none'",
                "worker-src 'self'",
                "manifest-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'none'",
                "object-src 'none'",
                "form-action 'self' ".$loginOrigin,
            ]));
        }
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }
        $isAsset = str_starts_with($request->path(), 'build/');
        if (! $isAsset) {
            $h->set('Cache-Control', 'no-store, private');
            $h->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
