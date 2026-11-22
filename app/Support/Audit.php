<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Append-only, hash-chained audit trail. Metadata is sanitized: no tokens, secrets or financial values. */
final class Audit
{
    private const REDACT = '/token|secret|sig|password|otp|code|ssn|account_number|routing|key|payload|body|amount|balance/i';

    public static function log(string $action, ?string $subjectType = null, ?string $subjectId = null, array $meta = [], ?string $workspaceId = null, ?string $scopeId = null): void
    {
        $ctx = AccessContext::current();
        $workspaceId ??= $ctx?->workspace->id;
        $actorType = $ctx?->actorType ?? (auth()->check() ? 'user' : (app()->runningInConsole() ? 'system' : 'anonymous'));
        $actorId = $ctx?->clientId ? 'client:'.$ctx->clientId.'/'.$ctx->user->id : ($ctx?->user->id ?? auth()->id());

        $prev = DB::table('audit_events')->where('workspace_id', $workspaceId)->orderByDesc('created_at')->orderByDesc('id')->value('hash');
        $row = [
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspaceId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'membership_id' => $ctx?->membership->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'scope_id' => $scopeId,
            'correlation_id' => Correlation::id(),
            'ip_hash' => app()->runningInConsole() ? null : hash_hmac('sha256', (string) request()->ip(), (string) config('app.key')),
            'metadata' => json_encode(self::sanitize($meta)),
            'prev_hash' => $prev,
            'created_at' => now()->format('Y-m-d H:i:s.u'),
        ];
        $row['hash'] = hash('sha256', ($prev ?? '').json_encode(array_diff_key($row, ['hash' => 1])));
        DB::table('audit_events')->insert($row);
    }

    public static function sanitize(array $meta): array
    {
        $out = [];
        foreach ($meta as $k => $v) {
            if (is_string($k) && preg_match(self::REDACT, $k)) {
                $out[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $out[$k] = self::sanitize($v);
            } elseif (is_string($v) && strlen($v) > 200) {
                $out[$k] = substr($v, 0, 200).'…';
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
