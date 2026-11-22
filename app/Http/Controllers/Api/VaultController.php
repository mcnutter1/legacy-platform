<?php

namespace App\Http\Controllers\Api;

use App\Jobs\JobService;
use App\Services\Documents\ExtractionService;
use App\Services\ImportService;
use App\Services\PayrollService;
use App\Services\VaultService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VaultController extends ApiController
{
    public function upload(Request $r, VaultService $vault, JobService $jobs): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'max:'.(int) (config('legacy.vault.max_bytes') / 1024)], 'scope_id' => ['required', 'string', 'size:26'],
            'doc_type' => ['nullable', 'string', 'max:48'], 'title' => ['nullable', 'string', 'max:255'], 'tax_year' => ['nullable', 'integer'],
            'extract' => ['nullable', 'in:paystub,w2'], 'employment_id' => ['nullable', 'string', 'size:26']]);
        $f = $r->file('file');
        $res = $vault->store($this->ctx(), (string) file_get_contents($f->getRealPath()), [
            'scope_id' => $r->input('scope_id'), 'filename' => $f->getClientOriginalName(), 'title' => $r->input('title') ?: $f->getClientOriginalName(),
            'doc_type' => $r->input('doc_type', 'other'), 'tax_year' => $r->input('tax_year'), 'origin' => $r->input('origin') === 'mobile' ? 'mobile' : 'upload',
            'document_id' => $r->input('document_id'),
        ]);
        if ($r->filled('extract')) {
            // Extraction waits for the scan; the worker processes scan (priority 10) before extraction (100).
            $res['extraction_job'] = $jobs->enqueue($this->ctx(), 'document.extract', ['document_id' => $res['document_id'], 'schema' => $r->input('extract'),
                'employment_id' => $r->input('employment_id')], 'document', $res['document_id'], $r->input('scope_id'), 120)->present();
        }

        return $this->ok($res, 201);
    }

    public function versions(string $document): JsonResponse
    {
        $doc = DB::table('documents')->where('workspace_id', $this->ctx()->workspace->id)->where('id', $document)->first();
        if (! $doc || ! $this->ctx()->can('vault', 'read', $doc->scope_id)) {
            throw ApiError::notFound();
        }

        return $this->ok(DB::table('document_versions')->where('document_id', $doc->id)->orderByDesc('version_no')
            ->get(['id', 'version_no', 'original_filename', 'detected_mime', 'size_bytes', 'sha256', 'page_count', 'scan_status', 'content_flags', 'created_at'])
            ->map(fn ($v) => ['content_flags' => json_decode((string) $v->content_flags, true)] + (array) $v));
    }

    public function download(Request $r, VaultService $vault, string $document): JsonResponse
    {
        return $this->ok(['url' => $vault->issueDownload($this->ctx(), $document, $r->input('version_id'), $r->input('disposition', 'attachment')),
            'expires_in' => (int) config('legacy.vault.download_ttl_seconds')]);
    }

    public function extract(Request $r, JobService $jobs, string $document): JsonResponse
    {
        $doc = DB::table('documents')->where('workspace_id', $this->ctx()->workspace->id)->where('id', $document)->first();
        if (! $doc || ! $this->ctx()->can('vault', 'read', $doc->scope_id)) {
            throw ApiError::notFound();
        }
        $schema = (string) $r->input('schema', 'paystub');
        if (! in_array($schema, ExtractionService::SCHEMAS, true)) {
            throw new ApiError('validation_failed', 'Unknown extraction schema.');
        }

        return $this->accepted($jobs->enqueue($this->ctx(), 'document.extract', ['document_id' => $doc->id, 'schema' => $schema,
            'employment_id' => $r->input('employment_id'), 'engine' => $r->input('engine')], 'document', $doc->id, $doc->scope_id)->present());
    }

    public function approvePaystub(Request $r, PayrollService $svc, string $extraction): JsonResponse
    {
        $this->privileged();

        return $this->ok($svc->approveExtraction($this->ctx(), $extraction, $r->all()), 201);
    }

    public function rejectExtraction(Request $r, string $extraction): JsonResponse
    {
        $ctx = $this->ctx();
        $ex = DB::table('document_extractions')->where('workspace_id', $ctx->workspace->id)->where('id', $extraction)->first();
        if (! $ex || ! $ctx->can('vault', 'write', $ex->scope_id)) {
            throw ApiError::notFound();
        }
        DB::table('document_extractions')->where('id', $ex->id)->update(['status' => 'rejected', 'reviewed_by' => $ctx->user->id, 'updated_at' => now()]);
        DB::table('review_items')->where('workspace_id', $ctx->workspace->id)->where('dedup_key', 'extraction:'.$ex->id)->update(['status' => 'dismissed', 'resolved_at' => now(), 'resolved_by' => $ctx->user->id]);

        return $this->ok(['rejected' => true]);
    }

    public function releaseQuarantine(string $document): JsonResponse
    {
        $this->privileged();
        $ctx = $this->ctx();
        $doc = DB::table('documents')->where('workspace_id', $ctx->workspace->id)->where('id', $document)->first();
        if (! $doc || ! $ctx->can('vault', 'approve', $doc->scope_id) || $doc->status !== 'quarantined') {
            throw ApiError::forbidden();
        }
        $v = DB::table('document_versions')->find($doc->current_version_id);
        if (in_array($v->scan_status, ['infected', 'pending'], true)) {
            throw ApiError::conflict('The file has not passed scanning.');
        }
        DB::table('documents')->where('id', $doc->id)->update(['status' => 'clean', 'updated_at' => now()]);
        \App\Support\Audit::log('document.released', 'document', $doc->id, ['scan' => $v->scan_status], scopeId: $doc->scope_id);

        return $this->ok(['status' => 'clean']);
    }

    public function legalHold(Request $r, VaultService $vault, string $document): JsonResponse
    {
        $this->privileged();
        $vault->setLegalHold($this->ctx(), $document, $r->boolean('hold'));

        return $this->ok(['legal_hold' => $r->boolean('hold')]);
    }

    public function purge(VaultService $vault, string $document): JsonResponse
    {
        $this->privileged();
        $vault->purge($this->ctx(), $document);

        return $this->ok(['purged' => true]);
    }

    // ---------------- Imports ----------------

    public function importUpload(Request $r, ImportService $svc): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'max:30720'], 'provider' => ['required', 'string'], 'scope_id' => ['required', 'string', 'size:26']]);
        $f = $r->file('file');
        $res = $svc->upload($this->ctx(), (string) file_get_contents($f->getRealPath()), $f->getClientOriginalName(), $r->only(['provider', 'scope_id', 'connection_id', 'profile_id']));

        return response()->json(['data' => $res], 202);
    }

    public function importShow(ImportService $svc, string $batch): JsonResponse
    {
        $b = $svc->batch($this->ctx(), $batch);

        return $this->ok(['stats' => json_decode((string) $b->stats, true), 'coverage' => json_decode((string) $b->coverage, true),
            'reconciliation' => json_decode((string) $b->reconciliation, true)] + (array) $b);
    }

    public function importRows(Request $r, ImportService $svc, string $batch): JsonResponse
    {
        return response()->json($svc->rows($this->ctx(), $batch, $r->query('state'), (int) $r->query('limit', 100), $r->query('after')));
    }

    public function importResolve(Request $r, ImportService $svc, string $batch, string $row): JsonResponse
    {
        return $this->ok($svc->resolveRow($this->ctx(), $batch, $row, $r->all()));
    }

    public function importCommit(ImportService $svc, string $batch): JsonResponse
    {
        return $this->ok($svc->commit($this->ctx(), $batch));
    }

    public function importReverse(Request $r, ImportService $svc, string $batch): JsonResponse
    {
        $this->privileged();

        return $this->ok($svc->reverse($this->ctx(), $batch, (string) $r->input('reason', '')));
    }

    public function approveProfile(ImportService $svc, string $profile): JsonResponse
    {
        $this->privileged();
        $svc->approveProfile($this->ctx(), $profile);

        return $this->ok(['approved' => true]);
    }
}
