<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Minimal Streamable-HTTP MCP client (initialize → tools/list → tools/call). Results are data, never instructions. */
final class McpClient
{
    private ?string $sessionId = null;

    private int $seq = 0;

    private bool $ready = false;

    /** @param array<string, string> $headers */
    public function __construct(private readonly string $url, private readonly array $headers) {}

    public function initialize(): array
    {
        $res = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'mcnutt-legacy', 'version' => (string) config('app.version', '1')],
        ]);
        $this->notify('notifications/initialized');
        $this->ready = true;

        return $res;
    }

    /** @return list<array{name: string, description: ?string, inputSchema: array}> */
    public function tools(): array
    {
        $this->ready || $this->initialize();
        $out = [];
        $cursor = null;
        for ($i = 0; $i < 10; $i++) {
            $res = $this->rpc('tools/list', $cursor ? ['cursor' => $cursor] : new \stdClass);
            foreach ((array) ($res['tools'] ?? []) as $t) {
                if (is_array($t) && is_string($t['name'] ?? null)) {
                    $out[] = ['name' => mb_substr($t['name'], 0, 100), 'description' => isset($t['description']) ? mb_substr((string) $t['description'], 0, 500) : null,
                        'inputSchema' => is_array($t['inputSchema'] ?? null) ? $t['inputSchema'] : []];
                }
            }
            $cursor = $res['nextCursor'] ?? null;
            if (! $cursor) {
                break;
            }
        }

        return $out;
    }

    /** Returns the decoded payload of a tool call (structuredContent, else JSON text blocks). */
    public function call(string $tool, array $args): mixed
    {
        $this->ready || $this->initialize();
        $res = $this->rpc('tools/call', ['name' => $tool, 'arguments' => $args ?: new \stdClass]);
        $texts = [];
        foreach ((array) ($res['content'] ?? []) as $c) {
            if (($c['type'] ?? null) === 'text' && is_string($c['text'] ?? null)) {
                $texts[] = $c['text'];
            }
        }
        if (! empty($res['isError'])) {
            throw new IntegrationError('tool_error', "{$tool}: ".mb_substr(implode(' ', $texts) ?: 'tool reported an error', 0, 300));
        }
        if (isset($res['structuredContent']) && is_array($res['structuredContent'])) {
            return $res['structuredContent'];
        }
        $decoded = [];
        foreach ($texts as $t) {
            $j = json_decode($t, true);
            if (is_array($j)) {
                $decoded[] = $j;
            }
        }
        if (! $decoded) {
            throw new IntegrationError('unparseable', "{$tool} returned text, not structured data.");
        }

        return count($decoded) === 1 ? $decoded[0] : $decoded;
    }

    private function notify(string $method): void
    {
        try {
            $this->send(['jsonrpc' => '2.0', 'method' => $method]);
        } catch (IntegrationError) {
            // Notifications have no response; some servers answer 202/204 or nothing at all.
        }
    }

    private function rpc(string $method, array|object $params): array
    {
        $id = ++$this->seq;
        $msg = $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], $id);
        if (isset($msg['error'])) {
            $code = (int) ($msg['error']['code'] ?? 0);
            throw new IntegrationError($code === -32001 || $code === 401 ? 'unauthorized' : 'rpc_error', mb_substr((string) ($msg['error']['message'] ?? 'MCP error'), 0, 300));
        }

        return (array) ($msg['result'] ?? []);
    }

    private function send(array $body, ?int $expectId = null): array
    {
        $headers = $this->headers + ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
        if ($this->sessionId) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }
        try {
            $res = Http::withHeaders($headers)->timeout((int) config('legacy.integrations.timeout', 30))->connectTimeout(10)
                ->withOptions(['allow_redirects' => false])->asJson()->post($this->url, $body);
        } catch (ConnectionException) {
            throw new IntegrationError('unavailable', 'The provider did not respond.');
        }
        if ($sid = $res->header('Mcp-Session-Id')) {
            $this->sessionId = mb_substr($sid, 0, 200);
        }
        if ($res->status() === 401 || $res->status() === 403) {
            $msg = $res->json('error.message') ?? $res->json('error_description') ?? 'The provider rejected the credentials.';
            throw new IntegrationError('unauthorized', mb_substr((string) $msg, 0, 300));
        }
        if ($res->status() === 429) {
            throw new IntegrationError('rate_limited', 'The provider rate limit was reached. Try again later.');
        }
        if ($expectId === null) {
            return [];
        }
        if (! $res->successful()) {
            throw new IntegrationError('unavailable', "Provider error (HTTP {$res->status()}).");
        }
        $raw = $res->body();
        if (strlen($raw) > (int) config('legacy.integrations.max_response_bytes', 8 * 1024 * 1024)) {
            throw new IntegrationError('too_large', 'The provider response was too large.');
        }
        if (str_contains((string) $res->header('Content-Type'), 'text/event-stream')) {
            foreach (preg_split('/\r?\n\r?\n/', $raw) as $event) {
                $data = '';
                foreach (preg_split('/\r?\n/', $event) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $data .= ltrim(substr($line, 5));
                    }
                }
                $msg = $data !== '' ? json_decode($data, true) : null;
                if (is_array($msg) && ($msg['id'] ?? null) === $expectId) {
                    return $msg;
                }
            }
            throw new IntegrationError('unparseable', 'No response in the event stream.');
        }
        $msg = json_decode($raw, true);
        if (! is_array($msg)) {
            throw new IntegrationError('unparseable', 'The provider returned invalid JSON.');
        }

        return $msg;
    }
}
