<?php

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\Membership;
use App\Support\AccessContext;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Break-glass access to an emergency binder: request → secondary verification by a different steward →
 * time-boxed read grant → automatic expiry. Inactivity or a date alone never promotes anyone.
 */
final class GovernanceService
{
    public function request(AccessContext $ctx, string $binderId, string $reason): string
    {
        $binder = DB::table('emergency_binders')->where('workspace_id', $ctx->workspace->id)->where('id', $binderId)->whereNull('archived_at')->first();
        $recipients = $binder ? (json_decode((string) $binder->recipient_membership_ids, true) ?: []) : [];
        if (! $binder || ! in_array($ctx->membership->id, $recipients, true)) {
            throw ApiError::notFound();
        }
        if (mb_strlen(trim($reason)) < 20) {
            throw new ApiError('validation_failed', 'Describe the emergency (20+ characters).');
        }
        $id = (string) Str::ulid();
        DB::transaction(function () use ($id, $ctx, $binder, $reason) {
            DB::table('break_glass_requests')->insert(['id' => $id, 'workspace_id' => $ctx->workspace->id, 'scope_id' => $binder->scope_id, 'binder_id' => $binder->id,
                'requester_membership_id' => $ctx->membership->id, 'reason' => $reason, 'status' => 'requested', 'verifications' => json_encode([]),
                'created_by' => $ctx->user->id, 'created_at' => now(), 'updated_at' => now(), 'edit_version' => 1]);
            Audit::log('breakglass.requested', 'break_glass_request', $id, ['binder' => $binder->id], scopeId: $binder->scope_id);
            Outbox::publish('breakglass.requested', ['request_id' => $id], 'break_glass_request', $id);
        });

        return $id;
    }

    public function approve(AccessContext $ctx, string $requestId, string $note): array
    {
        $r = DB::table('break_glass_requests')->where('workspace_id', $ctx->workspace->id)->where('id', $requestId)->first();
        if (! $r || ! $ctx->isSteward($r->scope_id)) {
            throw ApiError::forbidden('Only a steward of the binder scope can verify this request.');
        }
        if ($r->requester_membership_id === $ctx->membership->id) {
            throw ApiError::forbidden('Requesters cannot verify their own request.');
        }
        if (! in_array($r->status, ['requested', 'verified'], true)) {
            throw ApiError::conflict('Request is '.$r->status.'.');
        }
        $binder = DB::table('emergency_binders')->find($r->binder_id);
        $ver = json_decode((string) $r->verifications, true) ?: [];
        foreach ($ver as $v) {
            if ($v['membership_id'] === $ctx->membership->id) {
                throw ApiError::conflict('You already verified this request.');
            }
        }
        $ver[] = ['membership_id' => $ctx->membership->id, 'at' => now()->toIso8601String(), 'note' => mb_substr($note, 0, 500)];
        $needed = $binder->requires_secondary_approval ? 2 : 1;
        $stewards = DB::table('scope_stewards')->where('scope_id', $r->scope_id)->where('membership_id', '!=', $r->requester_membership_id)->count();
        $needed = min($needed, max(1, $stewards));
        if (count($ver) < $needed) {
            DB::table('break_glass_requests')->where('id', $r->id)->update(['verifications' => json_encode($ver), 'status' => 'verified', 'updated_at' => now()]);
            Audit::log('breakglass.verified', 'break_glass_request', $r->id, scopeId: $r->scope_id);

            return ['status' => 'verified', 'verifications' => count($ver), 'required' => $needed];
        }

        return DB::transaction(function () use ($ctx, $r, $binder, $ver) {
            $expires = now()->addHours((int) $binder->release_hours);
            $grant = AccessGrant::create(['workspace_id' => $r->workspace_id, 'membership_id' => $r->requester_membership_id, 'scope_id' => $binder->scope_id,
                'domains' => ['governance', 'insurance', 'estate'], 'actions' => ['read'], 'purpose' => 'Emergency binder release '.$r->id,
                'granted_by' => $ctx->membership->id, 'approved_by' => $ctx->membership->id, 'expires_at' => $expires,
                'conditions' => ['break_glass_request' => $r->id, 'binder_id' => $binder->id]]);
            Membership::where('id', $r->requester_membership_id)->increment('access_version');
            DB::table('break_glass_requests')->where('id', $r->id)->update(['verifications' => json_encode($ver), 'status' => 'released', 'approved_by' => $ctx->membership->id,
                'released_at' => now(), 'expires_at' => $expires, 'grant_id' => $grant->id, 'updated_at' => now()]);
            Audit::log('breakglass.released', 'break_glass_request', $r->id, ['expires_at' => $expires->toIso8601String()], scopeId: $r->scope_id);
            Outbox::publish('breakglass.released', ['request_id' => $r->id], 'break_glass_request', $r->id);

            return ['status' => 'released', 'expires_at' => $expires->toIso8601String()];
        });
    }

    public function deny(AccessContext $ctx, string $requestId): void
    {
        $r = DB::table('break_glass_requests')->where('workspace_id', $ctx->workspace->id)->where('id', $requestId)->first();
        if (! $r || ! $ctx->isSteward($r->scope_id)) {
            throw ApiError::forbidden();
        }
        DB::table('break_glass_requests')->where('id', $r->id)->update(['status' => 'denied', 'updated_at' => now()]);
        if ($r->grant_id) {
            AccessGrant::where('id', $r->grant_id)->update(['revoked_at' => now()]);
        }
        Audit::log('breakglass.denied', 'break_glass_request', $r->id, scopeId: $r->scope_id);
    }

    /** Professional-review prompts: unfunded trusts, stale designations, minors as direct beneficiaries, successor gaps. */
    public function prompts(AccessContext $ctx): array
    {
        $out = [];
        if ($ctx->canAny('estate')) {
            foreach ($ctx->constrain(DB::table('trust_details as t'), 'estate', 'read', null, 't')->join('legal_entities as e', 'e.id', '=', 't.entity_id')
                ->whereIn('t.funding_status', ['unfunded', 'unknown'])->get(['e.name', 't.entity_id', 't.funding_status']) as $t) {
                $out[] = ['code' => 'unfunded_trust', 'message' => "{$t->name} funding status is {$t->funding_status}.", 'subject' => $t->entity_id];
            }
            foreach ($ctx->constrain(DB::table('beneficiary_designations'), 'estate', 'read')->whereNull('archived_at')
                ->where(fn ($q) => $q->whereNull('verified_on')->orWhere('verified_on', '<', now()->subYears(3)->toDateString()))->get() as $b) {
                $out[] = ['code' => 'stale_designation', 'message' => 'Beneficiary designation on a '.$b->subject_type.' has not been verified in three years.', 'subject' => $b->subject_id];
            }
            $minors = DB::table('persons')->where('workspace_id', $ctx->workspace->id)->where('birth_date', '>', now()->subYears(18)->toDateString())->pluck('id');
            foreach ($ctx->constrain(DB::table('beneficiary_designations'), 'estate', 'read')->whereNull('archived_at')->where('beneficiary_type', 'person')->whereIn('beneficiary_id', $minors)->get() as $b) {
                $out[] = ['code' => 'minor_direct_beneficiary', 'message' => 'A minor is named directly as beneficiary; consider a trust or custodian.', 'subject' => $b->subject_id];
            }
            if (! $ctx->constrain(DB::table('authority_roles'), 'estate', 'read')->whereIn('kind', ['executor', 'successor_trustee'])->whereNull('archived_at')->exists()) {
                $out[] = ['code' => 'successor_gap', 'message' => 'No executor or successor trustee is recorded.'];
            }
        }
        if (Membership::where('workspace_id', $ctx->workspace->id)->where('role', 'administrator')->where('status', 'active')->count() < 2) {
            $out[] = ['code' => 'single_admin', 'message' => 'Only one active administrator: designate a recovery administrator for a lost-account scenario.'];
        }
        if ($ctx->canAny('vault') && ! $ctx->constrain(DB::table('estate_documents'), 'estate', 'read')->whereNotNull('original_location')->exists()) {
            $out[] = ['code' => 'original_locations', 'message' => 'Record where originals of wills and trust agreements are kept.'];
        }

        return $out;
    }
}
