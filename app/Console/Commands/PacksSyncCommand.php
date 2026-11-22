<?php

namespace App\Console\Commands;

use App\Jobs\RunnerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Sync rule-pack release metadata from the runner (content hashes); approvals stay in the database. */
class PacksSyncCommand extends Command
{
    protected $signature = 'legacy:packs-sync';

    protected $description = 'Register the runner tax rule packs and their content hashes';

    public function handle(RunnerClient $runner): int
    {
        $res = $runner->run('selfcheck', (string) Str::ulid(), []);
        foreach ($res['result']['packs'] as $p) {
            $row = DB::table('tax_rule_packs')->where('jurisdiction', $p['jurisdiction'])->where('tax_year', $p['tax_year'])->where('version', $p['version'])->first();
            if ($row && $row->content_hash !== $p['hash']) {
                // Same version with different content: never silently keep an approval.
                DB::table('tax_rule_packs')->where('id', $row->id)->update(['content_hash' => $p['hash'], 'status' => 'draft', 'approved_by' => null, 'approved_at' => null,
                    'approval_note' => 'Content changed; approval cleared on '.now()->toDateString(), 'summary' => json_encode(['rules' => $p['rules'], 'release_note' => $p['release_note']]), 'updated_at' => now()]);
                $this->warn("{$p['jurisdiction']} {$p['tax_year']} {$p['version']}: content changed — approval cleared.");
            } elseif (! $row) {
                DB::table('tax_rule_packs')->insert(['id' => (string) Str::ulid(), 'jurisdiction' => $p['jurisdiction'], 'tax_year' => $p['tax_year'], 'version' => $p['version'],
                    'legislation_status' => $p['legislation_status'], 'status' => 'draft', 'content_hash' => $p['hash'],
                    'summary' => json_encode(['rules' => $p['rules'], 'release_note' => $p['release_note']]), 'created_at' => now(), 'updated_at' => now()]);
                $this->info("Registered {$p['jurisdiction']} {$p['tax_year']} {$p['version']} (draft).");
            }
        }

        return self::SUCCESS;
    }
}
