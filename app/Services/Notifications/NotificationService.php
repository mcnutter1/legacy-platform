<?php

namespace App\Services\Notifications;

use App\Models\Membership;
use App\Support\Audit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * In-app inbox plus email through notifications.mcnutt.cloud. Email bodies stay generic (no balances or
 * document content); a 2xx means "accepted", never "delivered"; ambiguous sends need an explicit retry.
 */
final class NotificationService
{
    public function notify(string $workspaceId, array $membershipIds, string $kind, string $title, ?string $body, ?string $link, string $dedupKey, string $severity = 'normal', ?string $dueOn = null): int
    {
        $n = 0;
        foreach (array_unique($membershipIds) as $mid) {
            $m = Membership::where('workspace_id', $workspaceId)->find($mid);
            if (! $m || ! $m->isActive()) {
                continue;
            }
            $pref = DB::table('notification_preferences')->where('membership_id', $mid)->first();
            if ($pref && in_array($kind, json_decode((string) $pref->muted_kinds, true) ?: [], true)) {
                continue;
            }
            $id = (string) Str::ulid();
            $inserted = DB::table('notifications')->insertOrIgnore(['id' => $id, 'workspace_id' => $workspaceId, 'membership_id' => $mid, 'kind' => $kind,
                'title' => mb_substr($title, 0, 255), 'body' => $body, 'link' => $link, 'dedup_key' => mb_substr($dedupKey, 0, 128), 'severity' => $severity,
                'due_on' => $dueOn, 'created_at' => now(), 'updated_at' => now()]);
            if (! $inserted) {
                continue;
            }
            $n++;
            $email = $m->user?->email;
            if ($email && (! $pref || ($pref->email_enabled && $pref->email_digest !== 'off'))) {
                DB::table('notification_deliveries')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $workspaceId, 'notification_id' => $id, 'channel' => 'email',
                    'recipient' => encrypt($email), 'status' => 'queued', 'next_attempt_at' => $this->nextWindow($pref), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return $n;
    }

    /** Members who can read a domain in a scope. */
    public function audience(string $workspaceId, string $domain, ?string $scopeId): array
    {
        return DB::table('access_grants')->where('workspace_id', $workspaceId)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($scopeId, fn ($q) => $q->where('scope_id', $scopeId))->get()
            ->filter(fn ($g) => in_array($domain, json_decode((string) $g->domains, true) ?: [], true) && in_array('read', json_decode((string) $g->actions, true) ?: [], true))
            ->pluck('membership_id')->unique()->values()->all();
    }

    private function nextWindow(?object $pref): \DateTimeInterface
    {
        $now = now();
        if (! $pref || ! $pref->quiet_start || ! $pref->quiet_end) {
            return $pref && $pref->email_digest === 'daily' ? $now->copy()->setTimezone($pref->timezone)->addDay()->setTime(7, 0)->utc() : $now;
        }
        $local = $now->copy()->setTimezone($pref->timezone);
        [$sh, $sm] = array_map('intval', explode(':', $pref->quiet_start));
        [$eh, $em] = array_map('intval', explode(':', $pref->quiet_end));
        $mins = $local->hour * 60 + $local->minute;
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;
        $inQuiet = $start <= $end ? ($mins >= $start && $mins < $end) : ($mins >= $start || $mins < $end);
        if (! $inQuiet) {
            return $now;
        }
        $resume = $local->copy()->setTime($eh, $em);
        if ($resume <= $local) {
            $resume->addDay();
        }

        return $resume->utc();
    }

    /** Process queued email deliveries (bounded batch). */
    public function dispatch(int $limit = 50): array
    {
        $stats = ['accepted' => 0, 'failed' => 0, 'retry' => 0, 'unknown' => 0, 'suppressed' => 0];
        $driver = config('legacy.notifications.driver');
        // Rows stuck in "sending" after a crash are ambiguous: never auto-resend.
        DB::table('notification_deliveries')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'delivery_unknown', 'last_error' => 'Worker stopped mid-send; resend explicitly if needed', 'updated_at' => now()]);
        $rows = DB::table('notification_deliveries')->where('status', 'queued')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->get();
        foreach ($rows as $d) {
            if (DB::table('notification_deliveries')->where('id', $d->id)->where('status', 'queued')->update(['status' => 'sending', 'updated_at' => now()]) !== 1) {
                continue;
            }
            $n = DB::table('notifications')->find($d->notification_id);
            $ws = DB::table('workspaces')->find($d->workspace_id);
            if (! $n || $driver === 'disabled') {
                DB::table('notification_deliveries')->where('id', $d->id)->update(['status' => 'suppressed', 'updated_at' => now()]);
                $stats['suppressed']++;
                continue;
            }
            $to = decrypt($d->recipient);
            $subject = mb_substr($n->title, 0, 150);
            $html = view('emails.notification', ['title' => $n->title, 'body' => $n->body, 'link' => $n->link ? url($n->link) : url('/'), 'workspace' => $ws?->name])->render();
            if ($driver === 'log') {
                Log::info('notification.email', ['to_hash' => substr(hash('sha256', $to), 0, 12), 'subject' => $subject]);
                $this->mark($d->id, 'accepted', 200, null, null);
                $stats['accepted']++;
                continue;
            }
            try {
                $res = Http::withToken((string) config('legacy.notifications.api_token'))->timeout((int) config('legacy.notifications.timeout'))->acceptJson()
                    ->post(rtrim((string) config('legacy.notifications.base_url'), '/').'/api/send.php', array_filter([
                        'channel' => 'email', 'to' => $to, 'from' => config('legacy.notifications.from'), 'subject' => str_replace(["\r", "\n"], ' ', $subject),
                        'body' => $html, 'provider' => config('legacy.notifications.provider'),
                    ]));
            } catch (ConnectionException $e) {
                $timedOut = str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');
                if ($timedOut) {
                    $this->mark($d->id, 'delivery_unknown', null, 'Timeout after request sent', null);
                    $stats['unknown']++;
                } else {
                    $this->retry($d, 'Connection failed');
                    $stats['retry']++;
                }
                continue;
            }
            $json = $res->json();
            if ($res->successful() && ! (is_array($json) && ($json['ok'] ?? true) === false)) {
                $this->mark($d->id, 'accepted', $res->status(), null, is_array($json) ? ($json['message_id'] ?? $json['id'] ?? null) : null);
                $stats['accepted']++;
            } elseif ($res->status() === 429 || $res->serverError()) {
                $this->retry($d, 'HTTP '.$res->status());
                $stats['retry']++;
            } else {
                $this->mark($d->id, 'failed', $res->status(), 'Rejected (HTTP '.$res->status().')', null);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function mark(string $id, string $status, ?int $code, ?string $error, ?string $msgId): void
    {
        DB::table('notification_deliveries')->where('id', $id)->update(['status' => $status, 'response_code' => $code, 'last_error' => $error,
            'provider_message_id' => $msgId, 'sent_at' => in_array($status, ['accepted'], true) ? now() : null, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
    }

    private function retry(object $d, string $error): void
    {
        $attempts = $d->attempts + 1;
        DB::table('notification_deliveries')->where('id', $d->id)->update([
            'status' => $attempts >= 6 ? 'failed' : 'queued', 'attempts' => $attempts, 'last_error' => $error,
            'next_attempt_at' => now()->addSeconds(min(3600, 60 * (2 ** ($attempts - 1))) + random_int(0, 30)), 'updated_at' => now(),
        ]);
    }

    public function resend(string $workspaceId, string $deliveryId): void
    {
        $n = DB::table('notification_deliveries')->where('workspace_id', $workspaceId)->where('id', $deliveryId)->whereIn('status', ['delivery_unknown', 'failed'])
            ->update(['status' => 'queued', 'next_attempt_at' => now(), 'updated_at' => now()]);
        if ($n) {
            Audit::log('notification.resend', 'notification_delivery', $deliveryId, [], $workspaceId);
        }
    }

    public function health(): array
    {
        if (config('legacy.notifications.driver') !== 'mcnutt') {
            return ['status' => config('legacy.notifications.driver') === 'log' ? 'healthy' : 'disabled', 'message' => 'Driver: '.config('legacy.notifications.driver')];
        }
        if (! config('legacy.notifications.api_token')) {
            return ['status' => 'unconfigured', 'message' => 'NOTIFICATIONS_API_TOKEN not set'];
        }
        $oldest = DB::table('notification_deliveries')->where('status', 'queued')->min('created_at');

        return ['status' => $oldest && now()->diffInMinutes($oldest, true) > 30 ? 'degraded' : 'healthy',
            'message' => 'Queued: '.DB::table('notification_deliveries')->where('status', 'queued')->count().', unknown: '.DB::table('notification_deliveries')->where('status', 'delivery_unknown')->count()];
    }
}
