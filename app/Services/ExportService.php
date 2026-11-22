<?php

namespace App\Services;

use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Domains;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Recipient-specific export grants. The link goes to the recipient by email; a separate verification code is
 * shown only to the sender for out-of-band delivery. Grants expire, are revocable and every download is logged.
 */
final class ExportService
{
    public function create(AccessContext $ctx, string $manifestId, array $data): array
    {
        $m = DB::table('report_manifests')->where('workspace_id', $ctx->workspace->id)->where('id', $manifestId)->first();
        if (! $m || ! $ctx->can('reports', 'export', $m->scope_id)) {
            throw ApiError::forbidden('You cannot export this report.');
        }
        foreach (Domains::REPORT_DEPENDENCIES[$m->kind] ?? [] as $d) {
            if (! $ctx->canAny($d, 'export')) {
                throw ApiError::forbidden('Exporting this report requires export rights on '.Domains::LABELS[$d].'.');
            }
        }
        if ($m->status !== 'ready') {
            throw ApiError::conflict('The report is not ready yet.');
        }
        $email = strtolower(trim((string) ($data['recipient_email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || empty($data['recipient_name'])) {
            throw new ApiError('validation_failed', 'Recipient name and a valid email are required.');
        }
        $days = max(1, min(30, (int) ($data['expires_in_days'] ?? 7)));
        $token = Str::random(48);
        $code = (string) random_int(100000, 999999);
        $id = (string) Str::ulid();
        DB::table('export_grants')->insert([
            'id' => $id, 'workspace_id' => $ctx->workspace->id, 'scope_id' => $m->scope_id, 'report_manifest_id' => $m->id,
            'recipient_name' => mb_substr((string) $data['recipient_name'], 0, 120), 'recipient_email' => $email, 'token_hash' => hash('sha256', $token),
            'verification_code_hash' => hash('sha256', $code), 'expires_at' => now()->addDays($days), 'max_downloads' => max(1, min(10, (int) ($data['max_downloads'] ?? 3))),
            'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
        ]);
        Audit::log('export.created', 'export_grant', $id, ['manifest' => $m->id, 'days' => $days], scopeId: $m->scope_id);

        return ['grant_id' => $id, 'link' => url('/x/'.$token), 'verification_code' => $code, 'expires_at' => now()->addDays($days)->toIso8601String(),
            'note' => 'Share the verification code with the recipient by phone or another channel. It is shown only once.'];
    }

    /** Explicit send: emails the link (never the file) to the recipient. */
    public function send(AccessContext $ctx, string $grantId, string $link): void
    {
        $g = DB::table('export_grants')->where('workspace_id', $ctx->workspace->id)->where('id', $grantId)->first();
        if (! $g || ! $ctx->can('reports', 'export', $g->scope_id) || $g->revoked_at) {
            throw ApiError::notFound();
        }
        if (! str_starts_with($link, url('/x/')) || ! hash_equals($g->token_hash, hash('sha256', substr($link, strlen(url('/x/')))))) {
            throw new ApiError('validation_failed', 'Link does not match this grant.');
        }
        DB::transaction(function () use ($g, $ctx, $link) {
            DB::table('export_grants')->where('id', $g->id)->update(['sent_at' => now()]);
            Outbox::publish('export.send', ['grant_id' => $g->id, 'link' => encrypt($link), 'from_name' => $ctx->user->name], 'export_grant', $g->id);
            Audit::log('export.sent', 'export_grant', $g->id, scopeId: $g->scope_id);
        });
    }

    public function revoke(AccessContext $ctx, string $grantId): void
    {
        $g = DB::table('export_grants')->where('workspace_id', $ctx->workspace->id)->where('id', $grantId)->first();
        if (! $g || ! $ctx->can('reports', 'export', $g->scope_id)) {
            throw ApiError::notFound();
        }
        DB::table('export_grants')->where('id', $g->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        Audit::log('export.revoked', 'export_grant', $g->id, scopeId: $g->scope_id);
    }

    public function lookup(string $token): object
    {
        $g = DB::table('export_grants')->where('token_hash', hash('sha256', $token))->first();
        if (! $g || $g->revoked_at || now()->greaterThan($g->expires_at) || $g->download_count >= $g->max_downloads) {
            throw ApiError::notFound();
        }

        return $g;
    }

    public function verifyAndDownload(string $token, string $code, string $ip): array
    {
        $key = 'export-verify:'.hash('sha256', $token);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ApiError('rate_limited', 'Too many attempts. Try again later.', 429);
        }
        $g = $this->lookup($token);
        if ($g->verification_attempts >= 8) {
            DB::table('export_grants')->where('id', $g->id)->update(['revoked_at' => now()]);
            throw ApiError::notFound();
        }
        if (! hash_equals((string) $g->verification_code_hash, hash('sha256', trim($code)))) {
            RateLimiter::hit($key, 900);
            DB::table('export_grants')->where('id', $g->id)->increment('verification_attempts');
            Audit::log('export.verify_failed', 'export_grant', $g->id, [], $g->workspace_id, $g->scope_id);
            throw new ApiError('invalid_code', 'That code is not correct.', 422);
        }
        $n = DB::table('export_grants')->where('id', $g->id)->where('download_count', '<', $g->max_downloads)->increment('download_count');
        if ($n !== 1) {
            throw ApiError::notFound();
        }
        $m = DB::table('report_manifests')->find($g->report_manifest_id);
        $doc = DB::table('documents')->find($m->document_id);
        $v = DB::table('document_versions')->find($doc->current_version_id);
        Audit::log('export.downloaded', 'export_grant', $g->id, ['download' => $g->download_count + 1], $g->workspace_id, $g->scope_id);

        return ['bytes' => app(VaultService::class)->decrypt($v), 'filename' => Str::slug($m->title).'.zip', 'mime' => 'application/zip'];
    }
}
