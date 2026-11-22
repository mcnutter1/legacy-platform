<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key is required on API mutations. The key is bound to principal + workspace + method/path/body,
 * so a replay returns the original response and a reused key with a different body is rejected.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $key)) {
            throw new ApiError('idempotency_key_required', 'An Idempotency-Key header (8-128 chars) is required.', 400);
        }
        $principal = 'u:'.($request->user()?->id ?? 'anon');
        $keyHash = hash('sha256', $key);
        $files = collect($request->allFiles())->flatten()->map(fn ($f) => hash_file('sha256', $f->getRealPath()))->implode(',');
        $reqHash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent().'|'.json_encode($request->except([])).'|'.$files);

        DB::table('idempotency_keys')->where('expires_at', '<', now())->delete();
        $existing = DB::table('idempotency_keys')->where('principal', $principal)->where('key_hash', $keyHash)->first();
        if ($existing) {
            if (! hash_equals($existing->request_hash, $reqHash)) {
                throw new ApiError('idempotency_mismatch', 'This Idempotency-Key was used with a different request.', 422);
            }
            if ($existing->response_status === null) {
                throw ApiError::conflict('The original request is still in progress.');
            }

            return response($existing->response_body, $existing->response_status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
        }
        try {
            DB::table('idempotency_keys')->insert([
                'principal' => $principal, 'key_hash' => $keyHash, 'request_hash' => $reqHash,
                'created_at' => now(), 'expires_at' => now()->addDay(),
            ]);
        } catch (QueryException) {
            throw ApiError::conflict('The original request is still in progress.');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('idempotency_keys')->where('principal', $principal)->where('key_hash', $keyHash)->delete();
            throw $e;
        }
        if ($response->getStatusCode() >= 500 || $response->getStatusCode() === 409 || $response->getStatusCode() === 428) {
            DB::table('idempotency_keys')->where('principal', $principal)->where('key_hash', $keyHash)->delete();
        } else {
            DB::table('idempotency_keys')->where('principal', $principal)->where('key_hash', $keyHash)
                ->update(['response_status' => $response->getStatusCode(), 'response_body' => mb_substr((string) $response->getContent(), 0, 1_000_000)]);
        }

        return $response;
    }
}
