<?php

namespace Tests\Feature;

use App\Jobs\JobService;
use App\Jobs\RunnerClient;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Compute jobs end-to-end against the synthetic demo family (tax, planning, estate, debt, reports). */
class ComputeJobsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_executable((string) config('legacy.runner.python'))) {
            $this->markTestSkipped('Runner virtualenv not available (runners/.venv).');
        }
        $this->artisan('legacy:packs-sync')->assertSuccessful();
        $this->artisan('legacy:demo', ['uid' => 'demo-owner'])->assertSuccessful();
        $this->user = User::where('central_principal_id', 'demo-owner')->firstOrFail();
        $this->ws = Membership::where('user_id', $this->user->id)->firstOrFail()->workspace_id;
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

    public function test_tax_run_produces_an_estimate_with_trace_and_never_claims_calculated_with_draft_packs(): void
    {
        $unit = DB::table('tax_units')->where('workspace_id', $this->ws)->value('id');
        $runId = $this->signIn($this->user)->send('POST', $this->wsUrl($this->ws, "tax/units/{$unit}/runs"), ['kind' => 'return'])->assertStatus(202)->json('data.run_id');
        $this->work();
        $run = $this->signIn($this->user)->getJson($this->wsUrl($this->ws, "tax/runs/{$runId}"))->assertOk()->json('data');
        $this->assertSame('estimate', $run['status']);
        $this->assertNotEmpty($run['lines']);
        $this->assertGreaterThan(0, (float) $run['summary']['combined']['total_tax']);
        $this->assertStringContainsString('pending professional approval', implode(' ', $run['summary']['status_reasons']));
        $trace = $this->signIn($this->user)->getJson($this->wsUrl($this->ws, "tax/runs/{$runId}/trace?node=f1040.24"))->assertOk()->json('data.trace');
        $this->assertNotEmpty($trace);
    }

    public function test_what_if_overrides_never_change_canonical_facts(): void
    {
        $unit = DB::table('tax_units')->where('workspace_id', $this->ws)->value('id');
        $before = DB::table('tax_documents')->where('workspace_id', $this->ws)->orderBy('id')->pluck('boxes')->all();
        $run = $this->signIn($this->user)->send('POST', $this->wsUrl($this->ws, "tax/units/{$unit}/runs"), ['kind' => 'return', 'overrides' => ['adjustments' => ['hsa' => '8550']]])
            ->assertStatus(202)->json('data.run_id');
        $this->work();
        $this->assertSame('strategy', DB::table('tax_runs')->where('id', $run)->value('kind'));
        $this->assertSame($before, DB::table('tax_documents')->where('workspace_id', $this->ws)->orderBy('id')->pluck('boxes')->all());
    }

    public function test_planning_estate_and_report_jobs_complete(): void
    {
        $sc = DB::table('plan_scenarios')->where('workspace_id', $this->ws)->value('id');
        $det = $this->signIn($this->user)->send('POST', $this->wsUrl($this->ws, "planning/scenarios/{$sc}/runs"), ['kind' => 'deterministic'])->json('data.run_id');
        $mc = $this->signIn($this->user)->send('POST', $this->wsUrl($this->ws, "planning/scenarios/{$sc}/runs"), ['kind' => 'montecarlo', 'paths' => 200, 'seed' => 7])->json('data.run_id');
        $es = DB::table('estate_scenarios')->where('workspace_id', $this->ws)->value('id');
        $er = $this->signIn($this->user)->send('POST', $this->wsUrl($this->ws, "estate/scenarios/{$es}/runs"))->json('data.run_id');
        $scope = DB::table('scopes')->where('workspace_id', $this->ws)->where('kind', 'joint')->value('id');
        $rep = $this->signIn($this->user)->send('POST', $this->wsUrl($this->ws, 'reports'), ['kind' => 'net_worth', 'scope_id' => $scope])->assertStatus(202)->json('data.manifest_id');
        $this->work();

        $d = $this->signIn($this->user)->getJson($this->wsUrl($this->ws, "planning/runs/{$det}"))->json('data');
        $this->assertSame('completed', $d['status']);
        $this->assertNotEmpty($d['years']);
        $m = $this->signIn($this->user)->getJson($this->wsUrl($this->ws, "planning/runs/{$mc}"))->json('data');
        $this->assertSame('completed', $m['status']);
        $this->assertSame(7, (int) $m['seed']);
        $this->assertGreaterThanOrEqual(0, $m['summary']['probability_success']);
        $this->assertSame('completed', DB::table('estate_runs')->where('id', $er)->value('status'));
        $this->assertSame('ready', DB::table('report_manifests')->where('id', $rep)->value('status'));
    }

    public function test_jobs_fail_closed_when_the_initiator_loses_access(): void
    {
        $sc = DB::table('plan_scenarios')->where('workspace_id', $this->ws)->value('id');
        $this->signIn($this->user)->send('POST', $this->wsUrl($this->ws, "planning/scenarios/{$sc}/runs"), ['kind' => 'deterministic'])->assertStatus(202);
        DB::table('memberships')->where('workspace_id', $this->ws)->update(['status' => 'suspended']);
        $this->work();
        $job = DB::table('compute_jobs')->where('workspace_id', $this->ws)->orderByDesc('created_at')->first();
        $this->assertSame('failed', $job->status);
        $this->assertNotSame('completed', DB::table('plan_runs')->where('scenario_id', $sc)->orderByDesc('created_at')->value('status'));
    }
}
