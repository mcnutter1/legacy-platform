<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthUnavailable;
use App\Services\Auth\CentralSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Requires a verified central identity; revalidates within the window and fails closed. */
class EnsureCentralAuth
{
    public function __construct(private readonly CentralSession $session) {}

    public function handle(Request $request, Closure $next, ?string $fresh = null): Response
    {
        $json = $request->is('api/*') || $request->expectsJson();
        $user = $this->session->user();
        if (! $user) {
            if ($request->isMethod('GET') && ! $json) {
                // Keep the query so an OAuth return (code/state) survives a sign-in round trip.
                $request->session()->put('url.intended', '/'.ltrim($request->path(), '/').($request->getQueryString() !== null ? '?'.mb_substr($request->getQueryString(), 0, 2048) : ''));
            }

            return $json ? response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Sign in required.']], 401) : redirect('/auth/login');
        }
        $maxAge = $fresh ? (int) config('legacy.auth.fresh_seconds') : (int) config('legacy.auth.revalidate_seconds');
        try {
            if (! $this->session->ensureFresh($maxAge)) {
                return $json ? response()->json(['error' => ['code' => 'session_ended', 'message' => 'Your session ended. Sign in again.']], 401) : redirect('/auth/login');
            }
        } catch (AuthUnavailable) {
            return $json ? response()->json(['error' => ['code' => 'auth_unavailable', 'message' => 'Sign-in service unavailable.']], 503)
                : response()->view('errors.auth-unavailable', [], 503);
        }

        return $next($request);
    }
}
