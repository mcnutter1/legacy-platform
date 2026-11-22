<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\CentralSession;
use App\Services\Auth\StepUp;
use App\Support\AccessContext;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function ctx(): AccessContext
    {
        return app(AccessContext::class);
    }

    protected function ok(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json(['data' => $data], $status, $headers);
    }

    protected function accepted(array $job): JsonResponse
    {
        return response()->json(['data' => ['job' => $job]], 202, ['Location' => '/api/v1/workspaces/'.$this->ctx()->workspace->id.'/jobs/'.$job['id']]);
    }

    protected function ifMatch(Request $r): ?int
    {
        $h = trim((string) $r->header('If-Match'), " W/\"");

        return $h === '' ? null : (ctype_digit($h) ? (int) $h : -1);
    }

    /** Fresh central revalidation + application step-up for privileged actions. */
    protected function privileged(): void
    {
        if (! app(CentralSession::class)->ensureFresh((int) config('legacy.auth.fresh_seconds'))) {
            throw new ApiError('session_ended', 'Sign in again to continue.', 401);
        }
        StepUp::require();
    }

    protected function validated(Request $r, array $rules): array
    {
        return validator($r->all(), $rules)->validate();
    }
}
