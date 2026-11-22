<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;

/** Daily due-date rules. Notifications are generic and deduplicated per item and due date. */
final class AlertService
{
    public function __construct(private readonly NotificationService $notify) {}

    public function run(?string $workspaceId = null): array
    {
        $stats = [];
        $workspaces = DB::table('workspaces')->when($workspaceId, fn ($q) => $q->where('id', $workspaceId))->get();
        foreach ($workspaces as $ws) {
            $count = 0;
            $today = now()->setTimezone($ws->timezone)->toDateString();
            $soon = fn (int $days) => now()->setTimezone($ws->timezone)->addDays($days)->toDateString();
            $send = function (string $domain, ?string $scopeId, string $kind, string $title, string $link, string $key, ?string $due, string $sev = 'normal') use ($ws, &$count) {
                $count += $this->notify->notify($ws->id, $this->notify->audience($ws->id, $domain, $scopeId), $kind, $title, null, $link, $key, $sev, $due);
            };
            foreach (DB::table('tax_payments')->where('workspace_id', $ws->id)->where('status', 'planned')->whereBetween('due_on', [$today, $soon(14)])->whereNull('archived_at')->get() as $p) {
                $send('tax', $p->scope_id, 'tax_estimate_due', "{$p->jurisdiction} estimated tax installment due {$p->due_on}", '/tax', "taxpay:{$p->id}", $p->due_on, 'high');
            }
            foreach (DB::table('insurance_policies')->where('workspace_id', $ws->id)->where('status', 'active')->whereBetween('term_end', [$today, $soon(45)])->whereNull('archived_at')->get() as $p) {
                $send('insurance', $p->scope_id, 'policy_renewal', "Policy renewal approaching ({$p->term_end})", '/insurance', "renew:{$p->id}:{$p->term_end}", $p->term_end);
            }
            foreach (DB::table('equity_grants')->where('workspace_id', $ws->id)->whereIn('status', ['active', 'fully_vested'])->whereBetween('expiration_date', [$today, $soon(90)])->whereNull('archived_at')->get() as $g) {
                $send('compensation', $g->scope_id, 'grant_expiring', "Equity grant expires {$g->expiration_date}", '/equity', "grantexp:{$g->id}", $g->expiration_date, 'high');
            }
            foreach (DB::table('equity_grants')->where('workspace_id', $ws->id)->where('election_83b_filed', false)->whereBetween('election_83b_deadline', [$today, $soon(10)])->get() as $g) {
                $send('compensation', $g->scope_id, '83b_deadline', "83(b) election deadline {$g->election_83b_deadline}", '/equity', "83b:{$g->id}", $g->election_83b_deadline, 'high');
            }
            foreach (DB::table('legal_obligations')->where('workspace_id', $ws->id)->where('status', 'open')->whereBetween('due_on', [$today, $soon(30)])->whereNull('archived_at')->get() as $o) {
                $send('governance', $o->scope_id, 'filing_due', "Filing due {$o->due_on}: {$o->title}", '/governance', "oblig:{$o->id}:{$o->due_on}", $o->due_on);
            }
            foreach (DB::table('estate_documents')->where('workspace_id', $ws->id)->where('status', 'executed')->whereBetween('review_due_on', [$today, $soon(30)])->whereNull('archived_at')->get() as $e) {
                $send('estate', $e->scope_id, 'estate_review', 'Estate document review due', '/estate', "estrev:{$e->id}:{$e->review_due_on}", $e->review_due_on);
            }
            foreach (DB::table('source_connections')->where('workspace_id', $ws->id)->whereNull('archived_at')->get() as $c) {
                if ($c->last_success_at && now()->diffInHours($c->last_success_at, true) > $c->freshness_hours) {
                    $send('ledger', $c->scope_id, 'stale_source', "Data source \"{$c->label}\" is stale", '/imports', "stale:{$c->id}:".substr((string) $c->last_success_at, 0, 10), null);
                }
            }
            foreach (DB::table('tasks')->where('workspace_id', $ws->id)->whereIn('status', ['open', 'in_progress'])->whereBetween('due_on', [$today, $soon(3)])->whereNull('archived_at')->get() as $t) {
                $members = $t->assignee_membership_id ? [$t->assignee_membership_id] : $this->notify->audience($ws->id, $t->domain, $t->scope_id);
                $count += $this->notify->notify($ws->id, $members, 'task_due', "Task due {$t->due_on}", null, '/tasks', "task:{$t->id}:{$t->due_on}", 'normal', $t->due_on);
            }
            // RMD reminder in Q4 for owners at or past the statutory age.
            if (now()->month >= 10) {
                foreach (DB::table('accounts as a')->join('persons as p', 'p.id', '=', 'a.owner_person_id')->where('a.workspace_id', $ws->id)
                    ->whereIn('a.kind', ['ira', '401k', '403b', '457b', 'sep_ira'])->whereNull('a.archived_at')->whereNotNull('p.birth_date')->get(['a.id', 'a.scope_id', 'p.birth_date']) as $a) {
                    $by = (int) substr($a->birth_date, 0, 4);
                    $age = now()->year - $by;
                    if ($age >= ($by <= 1959 ? 73 : 75)) {
                        $send('ledger', $a->scope_id, 'rmd_due', 'Required minimum distribution due by December 31', '/accounts', "rmd:{$a->id}:".now()->year, now()->year.'-12-31', 'high');
                    }
                }
            }
            // Stale property valuations (> 1 year).
            foreach (DB::table('properties')->where('workspace_id', $ws->id)->where('status', 'owned')->whereNull('archived_at')->get() as $p) {
                $last = DB::table('valuations')->where('subject_type', 'property')->where('subject_id', $p->id)->where('status', 'accepted')->max('as_of');
                if (! $last || $last < now()->subYear()->toDateString()) {
                    $send('real_estate', $p->scope_id, 'stale_valuation', 'A property valuation is more than a year old', '/real-estate', "val:{$p->id}:".now()->format('Y-m'), null, 'low');
                }
            }
            $stats[$ws->id] = $count;
        }
        // Expire break-glass grants.
        DB::table('break_glass_requests')->where('status', 'released')->where('expires_at', '<', now())->update(['status' => 'expired']);

        return $stats;
    }
}
