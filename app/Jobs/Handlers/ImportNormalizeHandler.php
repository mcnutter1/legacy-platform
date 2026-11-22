<?php

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Models\ComputeJob;
use App\Services\ImportService;
use App\Services\VaultService;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;

class ImportNormalizeHandler implements JobHandler
{
    public function authorization(ComputeJob $job): array
    {
        return [['ledger', 'write', $job->scope_id]];
    }

    public function runnerType(): ?string
    {
        return 'import.normalize';
    }

    public function prepare(ComputeJob $job, AccessContext $ctx): array
    {
        $batch = DB::table('import_batches')->find($job->params['batch_id']);
        $version = DB::table('document_versions')->where('document_id', $batch->document_id)->orderByDesc('version_no')->first();
        $path = app(VaultService::class)->writeJobFile($version, (string) \App\Jobs\JobService::$currentWorkDir);
        $profile = $batch->profile_id ? DB::table('import_profiles')->find($batch->profile_id) : null;
        $accountMap = $profile ? (json_decode((string) $profile->account_map, true) ?: []) : [];
        // Default account mapping: match source labels to account names/external refs the initiator can write to.
        foreach ($ctx->constrain(DB::table('accounts'), 'ledger', 'write')->whereNull('archived_at')->get(['id', 'name', 'external_ref']) as $a) {
            $accountMap[$a->name] ??= $a->id;
            if ($a->external_ref) {
                $accountMap[$a->external_ref] ??= $a->id;
            }
        }
        return ['provider' => $batch->provider, 'file_path' => $path, 'profile' => [
            'mapping' => $profile ? (json_decode((string) $profile->mapping, true) ?: null) : null, 'account_map' => $accountMap,
            'category_map' => $profile ? (json_decode((string) $profile->category_map, true) ?: []) : [],
            'invert_sign' => (bool) (($profile ? json_decode((string) $profile->rules, true) : [])['invert_sign'] ?? false),
            'default_account_label' => $job->params['default_account_label'] ?? null,
        ]];
    }

    public function commit(ComputeJob $job, AccessContext $ctx, array $result): array
    {
        $batch = DB::table('import_batches')->find($job->params['batch_id']);

        return app(ImportService::class)->stage($batch, $result);
    }

    public function onFailure(ComputeJob $job): void
    {
        DB::table('import_batches')->where('id', $job->params['batch_id'] ?? '')->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
