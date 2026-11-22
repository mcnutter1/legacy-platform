<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** GET-only JSON client for documented read-only provider APIs. Responses are data, never instructions. */
final class RestClient
{
    public function __construct(private readonly string $base, private readonly array $headers) {}

    public function get(string $path = '', array $query = []): array
    {
        $url = rtrim($this->base, '/').($path !== '' ? '/'.ltrim($path, '/') : '');
        try {
            $res = Http::withHeaders($this->headers + ['Accept' => 'application/json'])->withUserAgent('McNuttLegacy/1.0 (+https://legacy.mcnutt.cloud)')
                ->timeout((int) config('legacy.integrations.timeout', 30))->connectTimeout(10)->withOptions(['allow_redirects' => false])
                ->get($url, array_filter($query, fn ($v) => $v !== null && $v !== ''));
        } catch (ConnectionException) {
            throw new IntegrationError('unavailable', 'The provider did not respond.');
        }
        if (strlen($res->body()) > (int) config('legacy.integrations.max_response_bytes', 8 * 1024 * 1024)) {
            throw new IntegrationError('too_large', 'The provider response was too large.');
        }
        $err = $res->json('error.message') ?? $res->json('error');
        $msg = is_string($err) ? mb_substr($err, 0, 300) : '';

        return match (true) {
            $res->status() === 401 => throw new IntegrationError('unauthorized', $msg ?: 'The provider rejected the key.'),
            $res->status() === 403 => throw new IntegrationError('forbidden', $msg ?: 'The provider refused access (check the key\'s scopes or the IP allow-list).'),
            $res->status() === 429 => throw new IntegrationError('rate_limited', 'The provider rate limit was reached. Try again later.'),
            $res->status() >= 300 && $res->status() < 400 => throw new IntegrationError('unavailable', 'The provider redirected the request; use the exact server address from its documentation.'),
            ! $res->successful() => throw new IntegrationError('unavailable', 'Provider error (HTTP '.$res->status().')'.($msg ? ": {$msg}" : '.')),
            ! is_array($res->json()) => throw new IntegrationError('unparseable', 'The provider returned invalid JSON.'),
            default => $res->json(),
        };
    }
}
