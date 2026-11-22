<?php

namespace App\Providers;

use App\Services\Auth\AuthGateway;
use App\Services\Auth\DevAuthGateway;
use App\Services\Auth\McNuttAuthGateway;
use App\Support\SchemaMacros;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        SchemaMacros::register();

        $this->app->singleton(AuthGateway::class, function () {
            $driver = config('legacy.auth.driver');
            if ($driver === 'dev') {
                if ($this->app->environment('production')) {
                    throw new RuntimeException('AUTH_DRIVER=dev is refused in production.');
                }

                return new DevAuthGateway;
            }

            return new McNuttAuthGateway(config('legacy.auth'));
        });
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            if (config('app.debug')) {
                throw new RuntimeException('APP_DEBUG must be false in production.');
            }
            if (config('legacy.notifications.driver') === 'log') {
                config(['legacy.notifications.driver' => 'disabled']);
            }
        }

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('mcp', fn (Request $r) => Limit::perMinute(120)->by(substr(hash('sha256', (string) $r->bearerToken()), 0, 16) ?: $r->ip()));
        RateLimiter::for('oauth', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
    }
}
