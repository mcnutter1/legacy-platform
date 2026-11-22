<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Jobs\JobService;
use App\Models\ComputeJob;
use App\Services\Documents\ExtractionService;
use App\Services\VaultService;
use App\Support\AccessContext;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

class DocumentExtractHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['vault', 'read', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return 'document.extract';
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $p = $job->params;
        $doc = DB::table('documents')->where('workspace_id', $ctx->workspace->id)->where('id', $p['document_id'])->first();
        if ($doc && $doc->status === 'quarantined' && DB::table('document_versions')->where('id', $doc->current_version_id)->value('scan_status') === 'pending') {
            throw new \App\Jobs\RunnerFailed('Waiting for malware scan', true);
        }
        if (! $doc || in_array($doc->status, ['quarantined', 'rejected', 'purged'], true)) {
            throw new ApiError('not_ready', 'Document is not available for extraction (it may still be in quarantine).');
        }
        $version = DB::table('document_versions')->find($doc->current_version_id);
        $svc = app(ExtractionService::class);
        $engine = $p['engine'] ?? (str_starts_with($version->detected_mime, 'image/') ? 'openai' : 'deterministic');
        if ($engine === 'openai') {
            $result = $svc->extractWithProvider($ctx, $doc, $version, $p['schema'] ?? 'paystub');

            return ['_done' => $svc->record($ctx, $doc, $version, $result, 'openai', $job->id, $p)];
        }

        return ['file_path' => app(VaultService::class)->writeJobFile($version, (string) JobService::$currentWorkDir), 'schema' => $p['schema'] ?? 'paystub'];
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        $p = $job->params;
        $doc = DB::table('documents')->find($p['document_id']);
        $version = DB::table('document_versions')->find($doc->current_version_id);
        $svc = app(ExtractionService::class);
        $summary = $svc->record($ctx, $doc, $version, $result, 'deterministic', $job->id, $p);
        if (($result['status'] ?? '') === 'needs_ocr' && $svc->providerAvailable($ctx)) {
            app(JobService::class)->enqueue($ctx, 'document.extract', $p + ['engine' => 'openai'], 'document', $doc->id, $doc->scope_id);
            $summary['follow_up'] = 'provider_ocr_enqueued';
        }

        return $summary;
    }
}
