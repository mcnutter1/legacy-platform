<?php

namespace Tests\Feature;

use App\Jobs\JobService;
use App\Jobs\RunnerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** End-to-end import through the Python runner: preview, commit, replay no-op, reverse. */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_executable((string) config('legacy.runner.python'))) {
            $this->markTestSkipped('Runner virtualenv not available (runners/.venv).');
        }
    }

    private function work(): void
    {
        $jobs = app(JobService::class);
        foreach (['interactive', 'bulk'] as $q) {
            while ($job = $jobs->claim($q)) {
                $jobs->execute($job, app(RunnerClient::class));
            }
        }
    }

    private function upload($ws, string $scopeId, string $csv)
    {
        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
            ->post($this->wsUrl($ws, 'imports'), ['file' => UploadedFile::fake()->createWithContent('monarch.csv', $csv), 'provider' => 'monarch', 'scope_id' => $scopeId]);
    }

    public function test_monarch_import_commits_once_and_replays_as_duplicates(): void
    {
        $u = $this->makeUser('owner');
        [$ws, , $joint] = $this->makeWorkspace($u);
        $this->signIn($u)->send('POST', $this->wsUrl($ws, 'r/accounts'), ['scope_id' => $joint->id, 'name' => 'Joint checking', 'kind' => 'checking', 'tax_bucket' => 'taxable'])->assertCreated();
        $csv = "Date,Merchant,Category,Account,Original Statement,Notes,Amount,Tags\n"
            ."2025-03-01,Grocer,Groceries,Joint checking,GROCER #12,,-84.12,\n"
            ."2025-03-02,Employer,Paychecks,Joint checking,PAYROLL,,3200.00,\n"
            ."2025-03-03,Cafe,Restaurants,Joint checking,CAFE,,=cmd|' /C calc'!A0,\n";

        $batch = $this->signIn($u)->upload($ws, $joint->id, $csv)->assertStatus(202)->json('data.batch_id');
        $this->work();
        $show = $this->signIn($u)->getJson($this->wsUrl($ws, "imports/{$batch}"))->assertOk()->json('data');
        $this->assertSame('previewed', $show['status']);
        $this->assertSame(3, (int) $show['row_count']);

        // The malformed amount needs review and must be skipped explicitly before commit.
        $review = $this->signIn($u)->getJson($this->wsUrl($ws, "imports/{$batch}/rows?state=needs_review"))->json('data');
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "imports/{$batch}/commit"))->assertStatus(409);
        foreach ($review as $row) {
            $this->signIn($u)->send('POST', $this->wsUrl($ws, "imports/{$batch}/rows/{$row['id']}"), ['action' => 'skip'])->assertOk();
        }
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "imports/{$batch}/commit"))->assertOk()->assertJsonPath('data.created', 2);
        $this->assertSame(2, DB::table('transactions')->where('workspace_id', $ws->id)->count());
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "imports/{$batch}/commit"))->assertOk()->assertJsonPath('data.replay', true);

        // Same file again: every valid row matches an existing fingerprint; nothing new is created.
        $again = $this->signIn($u)->upload($ws, $joint->id, $csv)->json('data.batch_id');
        $this->work();
        $stats = $this->signIn($u)->getJson($this->wsUrl($ws, "imports/{$again}"))->json('data.stats.matching');
        $this->assertNotNull($stats, json_encode([DB::table('import_batches')->find($again), DB::table('compute_jobs')->orderByDesc('created_at')->first(['status', 'error', 'type'])]));
        $this->assertSame(2, $stats['duplicate']);
        foreach ($this->signIn($u)->getJson($this->wsUrl($ws, "imports/{$again}/rows?state=needs_review"))->json('data') as $row) {
            $this->signIn($u)->send('POST', $this->wsUrl($ws, "imports/{$again}/rows/{$row['id']}"), ['action' => 'skip']);
        }
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "imports/{$again}/commit"))->assertOk()->assertJsonPath('data.created', 0);
        $this->assertSame(2, DB::table('transactions')->where('workspace_id', $ws->id)->whereNull('archived_at')->count());

        // Reversal archives the batch's transactions.
        $this->signIn($u)->send('POST', $this->wsUrl($ws, "imports/{$batch}/reverse"), ['reason' => 'Imported into the wrong account'])->assertOk();
        $this->assertSame(0, DB::table('transactions')->where('workspace_id', $ws->id)->whereNull('archived_at')->count());
    }
}
