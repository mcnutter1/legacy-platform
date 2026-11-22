<?php

namespace App\Services;

use App\Models\Record;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Encrypted document vault. Each version gets a random data key (XChaCha20-Poly1305 secretstream);
 * the data key is wrapped with an environment root key held outside the database and backups.
 */
final class VaultService
{
    private const CHUNK = 65536;

    public const ALLOWED = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic',
        'image/heif' => 'heic', 'text/csv' => 'csv', 'text/plain' => 'txt', 'application/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'application/zip' => 'zip', 'application/json' => 'json',
    ];

    public function store(AccessContext $ctx, string $bytes, array $meta): array
    {
        $scopeId = $meta['scope_id'] ?? null;
        if (! $scopeId || ! $ctx->can('vault', 'write', $scopeId)) {
            throw ApiError::forbidden('You cannot add documents to that scope.');
        }
        $size = strlen($bytes);
        if ($size === 0 || $size > (int) config('legacy.vault.max_bytes')) {
            throw new ApiError('file_size', 'File is empty or exceeds the size limit.', 413);
        }
        $mime = $this->detect($bytes, (string) ($meta['filename'] ?? ''));
        $flags = $this->inspect($bytes, $mime);
        if (! empty($flags['reject'])) {
            throw new ApiError('file_rejected', 'This file was rejected: '.$flags['reject'], 422);
        }
        $sha = hash('sha256', $bytes);
        $existing = DB::table('document_versions')->where('workspace_id', $ctx->workspace->id)->where('sha256', $sha)->value('document_id');
        if ($existing && empty($meta['allow_duplicate']) && empty($meta['document_id'])) {
            throw ApiError::conflict('This exact file is already in the vault.', ['duplicate_document_id' => $ctx->can('vault', 'read', DB::table('documents')->where('id', $existing)->value('scope_id')) ? $existing : null]);
        }

        return DB::transaction(function () use ($ctx, $bytes, $meta, $mime, $flags, $sha, $size, $scopeId) {
            $docId = $meta['document_id'] ?? null;
            if ($docId) {
                $doc = DB::table('documents')->where('workspace_id', $ctx->workspace->id)->where('id', $docId)->first();
                if (! $doc || ! $ctx->can('vault', 'write', $doc->scope_id)) {
                    throw ApiError::notFound();
                }
                $versionNo = (int) DB::table('document_versions')->where('document_id', $docId)->max('version_no') + 1;
            } else {
                $docId = (string) Str::ulid();
                DB::table('documents')->insert([
                    'id' => $docId, 'workspace_id' => $ctx->workspace->id, 'scope_id' => $scopeId,
                    'title' => mb_substr((string) ($meta['title'] ?? $meta['filename'] ?? 'Document'), 0, 255),
                    'doc_type' => $meta['doc_type'] ?? 'other', 'status' => 'quarantined', 'tax_year' => $meta['tax_year'] ?? null,
                    'origin' => $meta['origin'] ?? 'upload', 'tags' => isset($meta['tags']) ? json_encode($meta['tags']) : null,
                    'retention_policy' => $meta['retention_policy'] ?? 'indefinite', 'created_by' => $ctx->user->id, 'updated_by' => $ctx->user->id,
                    'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1,
                ]);
                $versionNo = 1;
            }
            $versionId = (string) Str::ulid();
            [$path, $wrapped, $keyId] = $this->encryptToDisk($ctx->workspace->id, $versionId, $bytes);
            DB::table('document_versions')->insert([
                'id' => $versionId, 'workspace_id' => $ctx->workspace->id, 'document_id' => $docId, 'version_no' => $versionNo,
                'original_filename' => isset($meta['filename']) ? mb_substr(basename((string) $meta['filename']), 0, 200) : null,
                'detected_mime' => $mime, 'size_bytes' => $size, 'sha256' => $sha, 'storage_path' => $path, 'wrapped_key' => $wrapped,
                'key_id' => $keyId, 'page_count' => $flags['pages'] ?? null, 'scan_status' => 'pending', 'content_flags' => json_encode($flags),
                'uploaded_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('documents')->where('id', $docId)->update(['current_version_id' => $versionId, 'status' => 'quarantined', 'updated_at' => now()]);
            Audit::log('document.uploaded', 'document', $docId, ['version' => $versionNo, 'mime' => $mime, 'bytes' => $size], scopeId: $scopeId);
            Outbox::publish('document.uploaded', ['document_id' => $docId, 'version_id' => $versionId, 'initiator_membership_id' => $ctx->membership->id], 'document', $docId);

            return ['document_id' => $docId, 'version_id' => $versionId, 'sha256' => $sha, 'mime' => $mime, 'status' => 'quarantined'];
        });
    }

    public function detect(string $bytes, string $filename): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($mime === 'text/plain' && $ext === 'csv') {
            $mime = 'text/csv';
        }
        if ($mime === 'application/zip' && $ext === 'xlsx' && str_contains(substr($bytes, 0, 2000), '[Content_Types].xml')) {
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }
        if (! isset(self::ALLOWED[$mime])) {
            throw new ApiError('file_type', "Files of type {$mime} are not accepted.", 415);
        }

        return $mime;
    }

    /** Media checks independent of the extension: active content, page and pixel limits. */
    public function inspect(string $bytes, string $mime): array
    {
        $flags = [];
        if ($mime === 'application/pdf') {
            foreach (['/JavaScript', '/JS ', '/JS(', '/Launch', '/EmbeddedFile', '/RichMedia', '/XFA'] as $needle) {
                if (str_contains($bytes, $needle)) {
                    $flags['active_content'][] = trim($needle, ' (');
                }
            }
            if (! empty($flags['active_content'])) {
                $flags['reject'] = 'PDF contains active content ('.implode(', ', $flags['active_content']).')';
            }
            $pages = preg_match_all('#/Type\s*/Page[^s]#', $bytes);
            $flags['pages'] = $pages ?: null;
            if ($pages > (int) config('legacy.vault.max_pages')) {
                $flags['reject'] = 'too many pages';
            }
            if (str_contains($bytes, '/Encrypt')) {
                $flags['encrypted_pdf'] = true;
            }
        } elseif (str_starts_with($mime, 'image/') && $mime !== 'image/heic' && $mime !== 'image/heif') {
            $info = @getimagesizefromstring($bytes);
            if (! $info) {
                $flags['reject'] = 'image could not be decoded';
            } elseif ($info[0] * $info[1] > (int) config('legacy.vault.max_pixels')) {
                $flags['reject'] = 'image dimensions exceed the limit';
            }
        } elseif (in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) {
            if (str_contains($bytes, 'vbaProject.bin')) {
                $flags['reject'] = 'macro-enabled workbook';
            }
        } elseif (in_array($mime, ['text/plain', 'text/csv'], true) && preg_match('/<\s*(script|html|svg|iframe)/i', substr($bytes, 0, 65536))) {
            $flags['markup_in_text'] = true;
        }

        return $flags;
    }

    private function kek(string $keyId): string
    {
        $keys = [config('legacy.vault.root_key_id') => config('legacy.vault.root_key')];
        foreach (array_filter(explode(',', (string) config('legacy.vault.previous_keys'))) as $pair) {
            [$id, $k] = array_pad(explode(':', $pair, 2), 2, null);
            if ($id && $k) {
                $keys[$id] = $k;
            }
        }
        $raw = isset($keys[$keyId]) ? base64_decode((string) $keys[$keyId], true) : false;
        if (! $raw || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Vault root key unavailable for key id '.$keyId);
        }

        return $raw;
    }

    private function wrap(string $dek): array
    {
        $keyId = (string) config('legacy.vault.root_key_id');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return [base64_encode($nonce.sodium_crypto_secretbox($dek, $nonce, $this->kek($keyId))), $keyId];
    }

    private function unwrap(string $wrapped, string $keyId): string
    {
        $raw = base64_decode($wrapped, true);
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $dek = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->kek($keyId));
        if ($dek === false) {
            throw new RuntimeException('Vault key unwrap failed');
        }

        return $dek;
    }

    private function encryptToDisk(string $workspaceId, string $versionId, string $bytes): array
    {
        $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$wrapped, $keyId] = $this->wrap($dek);
        $rel = $workspaceId.'/'.now()->format('Y/m').'/'.$versionId.'.bin';
        $abs = rtrim((string) config('legacy.vault.root'), '/').'/'.$rel;
        File::ensureDirectoryExists(dirname($abs), 0700);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);
        $fh = fopen($abs, 'xb');
        if (! $fh) {
            throw new RuntimeException('Cannot create vault file');
        }
        fwrite($fh, $header);
        $len = strlen($bytes);
        for ($off = 0; $off < $len || $len === 0; $off += self::CHUNK) {
            $chunk = substr($bytes, $off, self::CHUNK);
            $tag = $off + self::CHUNK >= $len ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($fh, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
            if ($len === 0) {
                break;
            }
        }
        fclose($fh);
        chmod($abs, 0600);
        sodium_memzero($dek);

        return [$rel, $wrapped, $keyId];
    }

    /** Decrypts and verifies integrity (AEAD per chunk + SHA-256 of plaintext). */
    public function decrypt(object $version): string
    {
        $abs = rtrim((string) config('legacy.vault.root'), '/').'/'.$version->storage_path;
        $fh = fopen($abs, 'rb');
        if (! $fh) {
            throw new RuntimeException('Vault file missing');
        }
        $dek = $this->unwrap($version->wrapped_key, $version->key_id);
        $header = fread($fh, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $dek);
        $out = '';
        $final = false;
        while (! feof($fh)) {
            $c = fread($fh, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($c === '' || $c === false) {
                break;
            }
            $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c);
            if ($res === false) {
                throw new RuntimeException('Vault integrity check failed');
            }
            [$plain, $tag] = $res;
            $out .= $plain;
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $final = true;
                break;
            }
        }
        fclose($fh);
        sodium_memzero($dek);
        if (! $final || ! hash_equals($version->sha256, hash('sha256', $out))) {
            throw new RuntimeException('Vault integrity check failed');
        }

        return $out;
    }

    public function writeJobFile(object $version, string $dir): string
    {
        $path = $dir.'/input.'.(self::ALLOWED[$version->detected_mime] ?? 'bin');
        file_put_contents($path, $this->decrypt($version));
        chmod($path, 0600);

        return $path;
    }

    /** Scan with ClamAV when available. Runs from CLI workers only. */
    public function scan(string $versionId): string
    {
        $v = DB::table('document_versions')->find($versionId);
        if (! $v) {
            return 'missing';
        }
        $engine = $this->scanner();
        if ($engine === null) {
            $status = 'unscanned';
        } else {
            $dir = rtrim((string) config('legacy.vault.job_dir'), '/').'/scan-'.Str::random(12);
            File::ensureDirectoryExists($dir, 0700);
            $file = $this->writeJobFile($v, $dir);
            try {
                $p = new \Symfony\Component\Process\Process([$engine, '--no-summary', $file], null, ['PATH' => '/usr/bin:/bin'], null, 120);
                $p->run();
                $status = match ($p->getExitCode()) { 0 => 'clean', 1 => 'infected', default => 'error' };
            } finally {
                File::deleteDirectory($dir);
            }
        }
        $docStatus = match (true) {
            $status === 'infected' => 'rejected',
            $status === 'clean', $status === 'unscanned' && ! config('legacy.vault.require_scan') => 'clean',
            default => 'quarantined',
        };
        DB::table('document_versions')->where('id', $versionId)->update(['scan_status' => $status, 'scan_engine' => $engine ? basename($engine) : null, 'updated_at' => now()]);
        DB::table('documents')->where('id', $v->document_id)->where('current_version_id', $versionId)->update([
            'status' => $docStatus, 'rejection_reason' => $status === 'infected' ? 'Malware detected' : null, 'updated_at' => now(),
        ]);
        if ($status === 'infected') {
            @unlink(rtrim((string) config('legacy.vault.root'), '/').'/'.$v->storage_path);
        }
        Audit::log('document.scanned', 'document', $v->document_id, ['status' => $status], $v->workspace_id);

        return $status;
    }

    private function scanner(): ?string
    {
        $cfg = config('legacy.vault.scanner');
        if ($cfg === 'none') {
            return null;
        }
        foreach (($cfg === 'auto' ? ['clamdscan', 'clamscan'] : [$cfg]) as $bin) {
            foreach (['/usr/bin/', '/usr/local/bin/'] as $dir) {
                if (is_executable($dir.$bin)) {
                    return $dir.$bin;
                }
            }
        }

        return null;
    }

    public function issueDownload(AccessContext $ctx, string $documentId, ?string $versionId = null, string $disposition = 'attachment'): string
    {
        $doc = DB::table('documents')->where('workspace_id', $ctx->workspace->id)->where('id', $documentId)->whereNull('archived_at')->first();
        if (! $doc || ! $ctx->can('vault', 'read', $doc->scope_id)) {
            throw ApiError::notFound();
        }
        if (in_array($doc->status, ['quarantined', 'rejected', 'purged'], true)) {
            throw ApiError::conflict('This document is not available for download ('.$doc->status.').');
        }
        $versionId ??= $doc->current_version_id;
        if (! DB::table('document_versions')->where('document_id', $doc->id)->where('id', $versionId)->exists()) {
            throw ApiError::notFound();
        }
        $token = Str::random(48);
        DB::table('download_tokens')->insert([
            'token_hash' => hash('sha256', $token), 'workspace_id' => $ctx->workspace->id, 'version_id' => $versionId, 'user_id' => $ctx->user->id,
            'disposition' => $disposition === 'inline' ? 'inline' : 'attachment', 'expires_at' => now()->addSeconds((int) config('legacy.vault.download_ttl_seconds')),
        ]);
        Audit::log('document.download_issued', 'document', $doc->id, ['version_id' => $versionId], scopeId: $doc->scope_id);

        return url('/d/'.$token);
    }

    /** Redeem a single-use token. Re-checks the holder's current access at redemption time. */
    public function redeem(string $token, ?\App\Models\User $user): array
    {
        $row = DB::table('download_tokens')->where('token_hash', hash('sha256', $token))->first();
        if (! $row || $row->used_at || now()->greaterThan($row->expires_at)) {
            throw ApiError::notFound();
        }
        $n = DB::table('download_tokens')->where('token_hash', $row->token_hash)->whereNull('used_at')->update(['used_at' => now()]);
        if ($n !== 1) {
            throw ApiError::notFound();
        }
        $v = DB::table('document_versions')->find($row->version_id);
        $doc = DB::table('documents')->find($v->document_id);
        if ($row->user_id) {
            if (! $user || $user->id !== $row->user_id) {
                throw ApiError::notFound();
            }
            $m = \App\Models\Membership::where('workspace_id', $row->workspace_id)->where('user_id', $user->id)->first();
            $ctx = $m ? new AccessContext($user, \App\Models\Workspace::find($row->workspace_id), $m) : null;
            if (! $ctx || ! $ctx->can('vault', 'read', $doc->scope_id)) {
                throw ApiError::notFound();
            }
        }
        Audit::log('document.downloaded', 'document', $doc->id, ['version_id' => $v->id, 'export_grant' => $row->export_grant_id], $row->workspace_id, $doc->scope_id);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $v->original_filename ?: ($doc->title.'.'.(self::ALLOWED[$v->detected_mime] ?? 'bin')));

        return ['bytes' => $this->decrypt($v), 'mime' => $v->detected_mime, 'filename' => $name, 'disposition' => $row->disposition];
    }

    public function purge(AccessContext $ctx, string $documentId): void
    {
        $doc = DB::table('documents')->where('workspace_id', $ctx->workspace->id)->where('id', $documentId)->first();
        if (! $doc || ! $ctx->can('vault', 'approve', $doc->scope_id)) {
            throw ApiError::forbidden();
        }
        if ($doc->legal_hold) {
            throw ApiError::conflict('Document is under legal hold.');
        }
        foreach (DB::table('document_versions')->where('document_id', $doc->id)->get() as $v) {
            @unlink(rtrim((string) config('legacy.vault.root'), '/').'/'.$v->storage_path);
            DB::table('document_versions')->where('id', $v->id)->update(['wrapped_key' => '', 'updated_at' => now()]);
        }
        DB::table('documents')->where('id', $doc->id)->update(['status' => 'purged', 'archived_at' => now(), 'updated_at' => now()]);
        Audit::log('document.purged', 'document', $doc->id, scopeId: $doc->scope_id);
    }

    public function setLegalHold(AccessContext $ctx, string $documentId, bool $hold): void
    {
        $doc = DB::table('documents')->where('workspace_id', $ctx->workspace->id)->where('id', $documentId)->first();
        if (! $doc || ! $ctx->can('vault', 'approve', $doc->scope_id)) {
            throw ApiError::forbidden();
        }
        DB::table('documents')->where('id', $doc->id)->update(['legal_hold' => $hold, 'updated_at' => now()]);
        Audit::log($hold ? 'document.legal_hold_on' : 'document.legal_hold_off', 'document', $doc->id, scopeId: $doc->scope_id);
    }

    /** Rewrap every data key under the current root key (rotation drill). */
    public function rewrapAll(): int
    {
        $n = 0;
        $current = (string) config('legacy.vault.root_key_id');
        foreach (DB::table('document_versions')->where('key_id', '!=', $current)->where('wrapped_key', '!=', '')->cursor() as $v) {
            $dek = $this->unwrap($v->wrapped_key, $v->key_id);
            [$wrapped, $keyId] = $this->wrap($dek);
            DB::table('document_versions')->where('id', $v->id)->update(['wrapped_key' => $wrapped, 'key_id' => $keyId]);
            sodium_memzero($dek);
            $n++;
        }

        return $n;
    }

    public function versionFor(AccessContext $ctx, string $documentId): object
    {
        $doc = DB::table('documents')->where('workspace_id', $ctx->workspace->id)->where('id', $documentId)->first();
        if (! $doc || ! $ctx->can('vault', 'read', $doc->scope_id)) {
            throw ApiError::notFound();
        }

        return DB::table('document_versions')->find($doc->current_version_id);
    }
}
