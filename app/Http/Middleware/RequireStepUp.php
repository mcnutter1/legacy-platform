<?php

namespace App\Http\Middleware;

use App\Services\Auth\StepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        StepUp::require();

        return $next($request);
    }
}
