<?php

namespace App\Services\Mcp;

use App\Jobs\JobService;
use App\Resources\Registry;
use App\Resources\ResourceService;
use App\Services\NetWorthService;
use App\Services\PortfolioService;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Domains;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MCP tools and resources. Every call re-applies workspace, scope and field policies through the delegated
 * AccessContext (token scopes ∩ user grants). Outputs exclude account numbers, raw files and provider URLs.
 */
final class McpServer
{
    public function __construct(private readonly AccessContext $ctx, private readonly array $scopes) {}

    public function tools(): array
    {
        $all = [
            ['name' => 'get_net_worth', 'scope' => 'wealth.read', 'title' => 'Net worth summary', 'readOnly' => true,
                'description' => 'Consolidated or personal net worth by category with as-of date, methodology and data coverage.',
                'inputSchema' => ['type' => 'object', 'properties' => ['as_of' => ['type' => 'string', 'format' => 'date'], 'person_id' => ['type' => 'string']], 'additionalProperties' => false]],
            ['name' => 'list_accounts', 'scope' => 'wealth.read', 'title' => 'Accounts and balances', 'readOnly' => true,
                'description' => 'Accounts you can access with latest balance, as-of date and source freshness. No account numbers.',
                'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]], 'additionalProperties' => false]],
            ['name' => 'get_portfolio', 'scope' => 'investments.read', 'title' => 'Portfolio allocation', 'readOnly' => true,
                'description' => 'Allocation, concentration flags and top positions with pricing freshness.',
                'inputSchema' => ['type' => 'object', 'properties' => ['as_of' => ['type' => 'string', 'format' => 'date']], 'additionalProperties' => false]],
            ['name' => 'get_tax_estimate', 'scope' => 'tax.read', 'title' => 'Tax estimate', 'readOnly' => true,
                'description' => 'Latest federal and NJ tax run for a year with status (estimate/calculated/reconciled/CPA-reviewed/unsupported) and open issues.',
                'inputSchema' => ['type' => 'object', 'properties' => ['tax_year' => ['type' => 'integer']], 'required' => ['tax_year'], 'additionalProperties' => false]],
            ['name' => 'get_plan_summary', 'scope' => 'plans.read', 'title' => 'Retirement plan summary', 'readOnly' => true,
                'description' => 'Plans and scenarios with their latest deterministic and Monte Carlo results.',
                'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false]],
            ['name' => 'run_plan_scenario', 'scope' => 'plans.run', 'title' => 'Run a plan scenario', 'readOnly' => false,
                'description' => 'Queue a deterministic, Monte Carlo or stress run of a saved scenario. Results are projections, separate from financial records.',
                'inputSchema' => ['type' => 'object', 'properties' => ['scenario_id' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['deterministic', 'montecarlo', 'stress']]],
                    'required' => ['scenario_id'], 'additionalProperties' => false]],
            ['name' => 'get_job_status', 'scope' => null, 'title' => 'Job status', 'readOnly' => true,
                'description' => 'Status of a job you started from this connection.',
                'inputSchema' => ['type' => 'object', 'properties' => ['job_id' => ['type' => 'string']], 'required' => ['job_id'], 'additionalProperties' => false]],
            ['name' => 'search_documents', 'scope' => 'docs.search', 'title' => 'Search documents', 'readOnly' => true,
                'description' => 'Search document titles and types you can access. Returns opaque resource IDs and metadata, not file contents.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'maxLength' => 80], 'tax_year' => ['type' => 'integer']], 'required' => ['query'], 'additionalProperties' => false]],
            ['name' => 'list_alerts', 'scope' => 'alerts.read', 'title' => 'Reminders and due dates', 'readOnly' => true,
                'description' => 'Your unread notifications and upcoming due items.',
                'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false]],
            ['name' => 'start_paystub_intake', 'scope' => 'payroll.intake.write', 'title' => 'Start paystub intake', 'readOnly' => false,
                'description' => 'Create a draft paystub intake for an employment record. Returns a short-lived first-party upload link (or accepts the file when attachment forwarding is enabled). Values are confirmed by you in the app before they change payroll.',
                'inputSchema' => ['type' => 'object', 'properties' => ['employment_id' => ['type' => 'string'], 'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 64],
                    'file_base64' => ['type' => 'string'], 'filename' => ['type' => 'string']], 'required' => ['employment_id', 'idempotency_key'], 'additionalProperties' => false]],
            ['name' => 'list_employments', 'scope' => 'payroll.intake.write', 'title' => 'Payroll profiles', 'readOnly' => true,
                'description' => 'Employment records you can submit paystubs for.',
                'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false]],
            ['name' => 'get_intake_status', 'scope' => 'payroll.intake.write', 'title' => 'Paystub intake status', 'readOnly' => true,
                'description' => 'Status of a paystub intake draft and its review link.',
                'inputSchema' => ['type' => 'object', 'properties' => ['draft_id' => ['type' => 'string']], 'required' => ['draft_id'], 'additionalProperties' => false]],
            ['name' => 'propose_record_change', 'scope' => 'records.propose', 'title' => 'Propose a change', 'readOnly' => false,
                'description' => 'Create a proposal in the review inbox. Nothing changes until a family member approves it in the app.',
                'inputSchema' => ['type' => 'object', 'properties' => ['domain' => ['type' => 'string', 'enum' => OAuthService::SCOPES['records.propose']['domains']], 'title' => ['type' => 'string', 'maxLength' => 200],
                    'details' => ['type' => 'object'], 'subject_type' => ['type' => 'string'], 'subject_id' => ['type' => 'string']], 'required' => ['domain', 'title', 'details'], 'additionalProperties' => false]],
        ];
        $out = [];
        foreach ($all as $t) {
            if ($t['scope'] !== null && ! in_array($t['scope'], $this->scopes, true)) {
                continue;
            }
            // An empty PHP array encodes as JSON [], which strict MCP clients reject (and then drop every tool).
            if (($t['inputSchema']['properties'] ?? null) === []) {
                $t['inputSchema']['properties'] = new \stdClass;
            }
            $out[] = ['name' => $t['name'], 'title' => $t['title'], 'description' => $t['description'], 'inputSchema' => $t['inputSchema'],
                'annotations' => ['readOnlyHint' => $t['readOnly'], 'destructiveHint' => false, 'idempotentHint' => $t['readOnly'], 'openWorldHint' => false]];
        }

        return $out;
    }

    public function call(string $name, array $args): array
    {
        $tool = collect($this->tools())->firstWhere('name', $name);
        if (! $tool) {
            throw new ApiError('unknown_tool', 'Tool not available for this connection.', 404);
        }
        Audit::log('mcp.tool_called', 'mcp_tool', $name, ['client' => $this->ctx->clientId]);
        $ctx = $this->ctx;

        return match ($name) {
            'get_net_worth' => $this->netWorth($args),
            'list_accounts' => $this->accounts((int) ($args['limit'] ?? 25)),
            'get_portfolio' => $this->portfolio($args),
            'get_tax_estimate' => $this->tax((int) $args['tax_year']),
            'get_plan_summary' => $this->plans(),
            'run_plan_scenario' => $this->runPlan((string) $args['scenario_id'], (string) ($args['kind'] ?? 'deterministic')),
            'get_job_status' => app(JobService::class)->visible($ctx, (string) $args['job_id'])->present(),
            'search_documents' => $this->searchDocs((string) $args['query'], $args['tax_year'] ?? null),
            'list_alerts' => $this->alerts(),
            'list_employments' => $this->employments(),
            'start_paystub_intake' => $this->paystubIntake($args),
            'get_intake_status' => $this->intakeStatus((string) $args['draft_id']),
            'propose_record_change' => $this->propose($args),
        };
    }

    private function netWorth(array $a): array
    {
        $nw = app(NetWorthService::class)->compute($this->ctx, $a['as_of'] ?? null, $a['person_id'] ?? null);

        return ['as_of' => $nw['as_of'], 'view' => $nw['view'], 'currency' => $nw['currency'], 'net_worth' => $nw['net_worth'], 'total_assets' => $nw['total_assets'],
            'total_liabilities' => $nw['total_liabilities'], 'liquid_assets' => $nw['liquid_assets'],
            'categories' => array_map(fn ($c) => ['section' => $c['section'], 'category' => $c['category'], 'value' => $c['value'], 'items' => count($c['items'])], $nw['categories']),
            'unvalued_items' => count($nw['unvalued']), 'methodology' => $nw['methodology']];
    }

    private function accounts(int $limit): array
    {
        $nw = app(NetWorthService::class);
        $rows = $this->ctx->constrain(DB::table('accounts'), 'ledger')->whereNull('archived_at')->where('status', 'open')->where('visibility', 'visible')->orderBy('name')->limit(min(50, $limit))->get();

        return ['accounts' => $rows->map(function ($a) use ($nw) {
            [$v, $m, $d] = $nw->accountValue($a->id, now()->toDateString());
            $src = $a->connection_id ? DB::table('source_connections')->find($a->connection_id) : null;

            return ['id' => $a->id, 'name' => $a->name, 'institution' => $a->institution, 'kind' => $a->kind, 'tax_bucket' => $a->tax_bucket,
                'balance' => $v, 'as_of' => $d, 'method' => $m, 'source_last_success' => $src?->last_success_at];
        })->all()];
    }

    private function portfolio(array $a): array
    {
        $p = app(PortfolioService::class)->summary($this->ctx, $a['as_of'] ?? null);

        return ['as_of' => $p['as_of'], 'total_value' => $p['total_value'], 'allocation' => $p['exposure']['asset_class'] ?? [], 'flags' => $p['flags'],
            'top_positions' => array_map(fn ($x) => ['symbol' => $x['symbol'], 'name' => $x['name'], 'value' => $x['value'], 'price_as_of' => $x['price_as_of']], array_slice($p['positions'], 0, 15)),
            'coverage' => ['stale_prices' => count($p['coverage']['stale_prices']), 'unvalued' => count($p['coverage']['unvalued'])]];
    }

    private function tax(int $year): array
    {
        $run = $this->ctx->constrain(DB::table('tax_runs'), 'tax', 'read', 'tax_year')->where('tax_year', $year)->whereNotIn('status', ['pending', 'failed'])->orderByDesc('created_at')->first();
        if (! $run) {
            return ['tax_year' => $year, 'status' => 'none', 'message' => 'No tax run exists for this year.'];
        }
        $s = json_decode((string) $run->summary, true) ?: [];
        $issues = json_decode((string) $run->issues, true) ?: [];

        return ['tax_year' => $year, 'run_id' => $run->id, 'status' => $run->status, 'status_reasons' => $s['status_reasons'] ?? [], 'scenario' => $run->scenario,
            'federal' => array_intersect_key($s['federal'] ?? [], array_flip(['agi', 'taxable_income', 'total_tax', 'total_payments', 'balance_due', 'refund', 'marginal_rate'])),
            'nj' => array_intersect_key($s['nj'] ?? [], array_flip(['gross_income', 'taxable_income', 'total_tax', 'balance_due', 'refund'])),
            'open_issues' => array_map(fn ($i) => $i['message'] ?? '', array_slice($issues, 0, 10)), 'computed_at' => $run->updated_at,
            'trace_reference' => 'Open Tax › Run '.$run->id.' in McNutt Legacy for line-level trace.'];
    }

    private function plans(): array
    {
        $scs = $this->ctx->constrain(DB::table('plan_scenarios'), 'planning')->whereNull('archived_at')->get();

        return ['scenarios' => $scs->map(function ($s) {
            $runs = DB::table('plan_runs')->where('scenario_id', $s->id)->where('status', 'completed')->orderByDesc('created_at')->get()->unique('kind');

            return ['scenario_id' => $s->id, 'name' => $s->name, 'is_baseline' => (bool) $s->is_baseline,
                'results' => $runs->mapWithKeys(fn ($r) => [$r->kind => ['run_id' => $r->id, 'summary' => array_diff_key(json_decode((string) $r->summary, true) ?: [], ['sensitivity' => 1]), 'at' => $r->created_at]])];
        })->all()];
    }

    private function runPlan(string $scenarioId, string $kind): array
    {
        $sc = $this->ctx->constrain(DB::table('plan_scenarios'), 'planning')->where('id', $scenarioId)->first();
        if (! $sc) {
            throw ApiError::notFound('Scenario not found.');
        }
        $runId = (string) Str::ulid();
        $kind = in_array($kind, ['deterministic', 'montecarlo', 'stress'], true) ? $kind : 'deterministic';
        DB::table('plan_runs')->insert(['id' => $runId, 'workspace_id' => $sc->workspace_id, 'scope_id' => $sc->scope_id, 'scenario_id' => $sc->id, 'kind' => $kind,
            'status' => 'pending', 'created_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
        $job = app(JobService::class)->enqueue($this->ctx, ['deterministic' => 'plan.project', 'montecarlo' => 'plan.montecarlo', 'stress' => 'plan.stress'][$kind],
            ['scenario_id' => $sc->id, 'run_id' => $runId, 'paths' => 1000], 'plan_run', $runId, $sc->scope_id);
        DB::table('plan_runs')->where('id', $runId)->update(['job_id' => $job->id]);

        return ['run_id' => $runId, 'job_id' => $job->id, 'status' => 'queued', 'note' => 'Check get_job_status; results are projections conditional on scenario assumptions.'];
    }

    private function searchDocs(string $q, ?int $year): array
    {
        $q = trim(mb_substr($q, 0, 80));
        $rows = $this->ctx->constrain(DB::table('documents'), 'vault')->whereNull('archived_at')->whereNotIn('status', ['quarantined', 'rejected', 'purged'])
            ->where('title', 'like', '%'.addcslashes($q, '%_\\').'%')->when($year, fn ($w) => $w->where('tax_year', $year))
            ->orderByDesc('updated_at')->limit((int) config('legacy.mcp.max_results'))->get(['id', 'title', 'doc_type', 'tax_year', 'updated_at']);

        return ['results' => $rows->map(fn ($d) => ['resource_uri' => 'legacy://document/'.$d->id, 'title' => $d->title, 'type' => $d->doc_type, 'tax_year' => $d->tax_year, 'updated_at' => $d->updated_at])->all(),
            'note' => 'File contents are not shared over this connection. Open documents in McNutt Legacy.'];
    }

    private function alerts(): array
    {
        $n = DB::table('notifications')->where('workspace_id', $this->ctx->workspace->id)->where('membership_id', $this->ctx->membership->id)->whereNull('read_at')
            ->orderByDesc('created_at')->limit(25)->get(['title', 'kind', 'due_on', 'severity', 'created_at']);

        return ['alerts' => $n->all()];
    }

    private function employments(): array
    {
        return ['employments' => $this->ctx->constrain(DB::table('employments'), 'compensation', 'write')->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('end_on')->orWhere('end_on', '>=', now()->subYear()->toDateString()))->get(['id', 'employer_name', 'title', 'person_id', 'pay_frequency'])->all()];
    }

    private function paystubIntake(array $a): array
    {
        $emp = $this->ctx->constrain(DB::table('employments'), 'compensation', 'write')->where('id', (string) $a['employment_id'])->first();
        if (! $emp || ! $this->ctx->can('vault', 'write', $emp->scope_id)) {
            throw ApiError::forbidden('You cannot submit paystubs for that employment.');
        }
        $key = 'mcp-intake:'.$this->ctx->membership->id.':'.hash('sha256', (string) $a['idempotency_key']);
        if ($existing = DB::table('upload_links')->where('workspace_id', $this->ctx->workspace->id)->where('token_hash', hash('sha256', $key))->first()) {
            return ['draft_id' => $existing->draft_id ?? null, 'status' => 'existing', 'note' => 'This idempotency key was already used.'];
        }
        if (! empty($a['file_base64'])) {
            if (! config('legacy.features.chat_attachment_forwarding')) {
                throw ApiError::unsupported('Attachment forwarding is disabled; use the upload link instead.');
            }
            $bytes = base64_decode((string) $a['file_base64'], true);
            if ($bytes === false || strlen($bytes) > 10 * 1024 * 1024) {
                throw new ApiError('invalid_file', 'File must be valid base64 under 10 MB.');
            }
            $stored = app(\App\Services\VaultService::class)->store($this->ctx, $bytes, ['scope_id' => $emp->scope_id, 'filename' => basename((string) ($a['filename'] ?? 'paystub.pdf')),
                'title' => 'Paystub via '.($this->ctx->clientId ?? 'MCP'), 'doc_type' => 'paystub', 'origin' => 'mcp']);
            app(JobService::class)->enqueue($this->ctx, 'document.extract', ['document_id' => $stored['document_id'], 'schema' => 'paystub', 'employment_id' => $emp->id,
                'client_provenance' => 'mcp:'.$this->ctx->clientId], 'document', $stored['document_id'], $emp->scope_id, 120);
            DB::table('upload_links')->insert(['token_hash' => hash('sha256', $key), 'workspace_id' => $this->ctx->workspace->id, 'membership_id' => $this->ctx->membership->id,
                'purpose' => 'paystub_mcp_key', 'draft_id' => $stored['document_id'], 'expires_at' => now()->addDay(), 'used_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return ['draft_id' => $stored['document_id'], 'status' => 'processing', 'review_url' => url('/review'),
                'note' => 'Extraction runs after a malware scan. Confirm the values in McNutt Legacy before they update payroll or tax estimates.'];
        }
        $token = Str::random(40);
        DB::table('upload_links')->insert(['token_hash' => hash('sha256', $token), 'workspace_id' => $this->ctx->workspace->id, 'membership_id' => $this->ctx->membership->id,
            'purpose' => 'paystub', 'context' => json_encode(['employment_id' => $emp->id, 'client' => $this->ctx->clientId]), 'expires_at' => now()->addMinutes(30),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('upload_links')->insert(['token_hash' => hash('sha256', $key), 'workspace_id' => $this->ctx->workspace->id, 'membership_id' => $this->ctx->membership->id,
            'purpose' => 'paystub_mcp_key', 'context' => json_encode(['upload_token_hash' => hash('sha256', $token)]), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);

        return ['status' => 'awaiting_upload', 'upload_url' => url('/u/'.$token), 'expires_in_minutes' => 30, 'draft_id' => hash('sha256', $token),
            'note' => 'Open the link while signed in to McNutt Legacy to upload or photograph the paystub.'];
    }

    private function intakeStatus(string $draftId): array
    {
        $doc = DB::table('documents')->where('workspace_id', $this->ctx->workspace->id)->where('id', $draftId)->first();
        if (! $doc) {
            $link = DB::table('upload_links')->where('workspace_id', $this->ctx->workspace->id)->where('token_hash', $draftId)->where('membership_id', $this->ctx->membership->id)->first();
            if (! $link) {
                throw ApiError::notFound();
            }
            if (! $link->draft_id) {
                return ['status' => now()->greaterThan($link->expires_at) ? 'expired' : 'awaiting_upload'];
            }
            $doc = DB::table('documents')->find($link->draft_id);
        }
        if (! $this->ctx->can('vault', 'read', $doc->scope_id)) {
            throw ApiError::notFound();
        }
        $ex = DB::table('document_extractions')->where('document_id', $doc->id)->orderByDesc('created_at')->first();
        $snap = $ex && $ex->target_type === 'payroll_snapshot' ? DB::table('payroll_snapshots')->find($ex->target_id) : null;

        return ['draft_id' => $doc->id, 'document_status' => $doc->status, 'extraction_status' => $ex?->status, 'payroll_status' => $snap?->status,
            'review_url' => url('/review'), 'note' => $snap ? 'Approved; a fresh tax forecast was queued.' : 'Awaiting your confirmation in the app.'];
    }

    private function propose(array $a): array
    {
        $domain = (string) $a['domain'];
        $scopes = $this->ctx->scopeIds($domain, 'write');
        if (! $scopes) {
            throw ApiError::forbidden('You cannot propose changes in that area.');
        }
        $id = (string) Str::ulid();
        DB::table('review_items')->insert(['id' => $id, 'workspace_id' => $this->ctx->workspace->id, 'scope_id' => $scopes[0], 'domain' => $domain, 'kind' => 'proposal',
            'subject_type' => isset($a['subject_type']) ? mb_substr((string) $a['subject_type'], 0, 32) : null, 'subject_id' => isset($a['subject_id']) && Str::isUlid($a['subject_id']) ? $a['subject_id'] : null,
            'title' => mb_substr((string) $a['title'], 0, 200), 'reason' => 'Proposed via '.($this->ctx->clientId ?? 'connected app').'. Review before applying.',
            'evidence' => json_encode(['details' => $a['details'], 'client' => $this->ctx->clientId]), 'severity' => 'normal', 'status' => 'open',
            'created_by' => $this->ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);

        return ['proposal_id' => $id, 'status' => 'pending_review', 'review_url' => url('/review')];
    }

    public function resources(?string $cursor): array
    {
        if (! in_array('docs.search', $this->scopes, true)) {
            return ['resources' => []];
        }
        $q = $this->ctx->constrain(DB::table('documents'), 'vault')->whereNull('archived_at')->whereNotIn('status', ['quarantined', 'rejected', 'purged'])->orderBy('id');
        if ($cursor && Str::isUlid($cursor)) {
            $q->where('id', '>', $cursor);
        }
        $rows = $q->limit(50)->get(['id', 'title', 'doc_type']);

        return ['resources' => $rows->map(fn ($d) => ['uri' => 'legacy://document/'.$d->id, 'name' => $d->title, 'mimeType' => 'application/json', 'description' => $d->doc_type])->all()]
            + ($rows->count() === 50 ? ['nextCursor' => $rows->last()->id] : []);
    }

    public function readResource(string $uri): array
    {
        if (! in_array('docs.search', $this->scopes, true) || ! preg_match('#^legacy://document/([0-9A-HJKMNP-TV-Z]{26})$#', $uri, $m)) {
            throw ApiError::notFound();
        }
        $d = $this->ctx->constrain(DB::table('documents'), 'vault')->where('id', $m[1])->whereNull('archived_at')->first();
        if (! $d) {
            throw ApiError::notFound();
        }
        $ex = DB::table('document_extractions')->where('document_id', $d->id)->where('status', 'approved')->orderByDesc('created_at')->first();
        $fields = [];
        foreach ((json_decode((string) $ex?->fields, true) ?: []) as $k => $v) {
            // Approved, reviewed values only; quotes and identifiers are omitted.
            $fields[$k] = $v['value'] ?? $v['current'] ?? null;
        }

        return ['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => json_encode(['title' => $d->title, 'type' => $d->doc_type, 'tax_year' => $d->tax_year,
            'status' => $d->status, 'reviewed_fields' => $fields, 'note' => 'Raw files are available only in the McNutt Legacy app.'])]]];
    }
}
