<?php

use App\Services\Auth\AuthUnavailable;
use App\Support\ApiError;
use App\Support\Correlation;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustConfiguredProxies::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'central' => \App\Http\Middleware\EnsureCentralAuth::class,
            'workspace' => \App\Http\Middleware\ResolveWorkspace::class,
            'stepup' => \App\Http\Middleware\RequireStepUp::class,
            'idempotent' => \App\Http\Middleware\Idempotency::class,
        ]);
        // Bearer/PKCE-authenticated machine endpoints carry no browser session.
        $middleware->validateCsrfTokens(except: ['mcp', 'oauth/token', 'oauth/register', 'oauth/revoke']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['payload', 'sig', 'code', 'token', 'totp']);
        $exceptions->dontReport([ApiError::class, AuthUnavailable::class]);

        $wantsJson = fn (Request $r) => $r->is('api/*') || $r->is('mcp') || $r->is('oauth/*') || $r->expectsJson();

        $exceptions->render(fn (ApiError $e) => response()->json($e->toArray(), $e->status));
        $exceptions->render(function (AuthUnavailable $e, Request $request) use ($wantsJson) {
            return $wantsJson($request)
                ? response()->json(['error' => ['code' => 'auth_unavailable', 'message' => 'Sign-in service unavailable.']], 503)
                : response()->view('errors.auth-unavailable', [], 503);
        });
        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json(['error' => ['code' => 'validation_failed', 'message' => 'Some fields need attention.', 'details' => $e->errors(), 'correlation_id' => Correlation::id()]], 422);
            }
        });
        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json(['error' => ['code' => 'csrf', 'message' => 'Your session expired. Reload the page.']], 419);
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Sign in required.']], 401);
            }
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404);
            }
        });
        $exceptions->render(function (\Throwable $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request) && ! config('app.debug')) {
                $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

                return response()->json(['error' => ['code' => $status >= 500 ? 'server_error' : 'http_'.$status,
                    'message' => $status >= 500 ? 'Something went wrong. Reference '.Correlation::id() : ($e->getMessage() ?: 'Request failed.'),
                    'correlation_id' => Correlation::id()]], $status);
            }
        });
    })->create();
