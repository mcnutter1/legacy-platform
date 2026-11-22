<?php

namespace App\Http\Controllers;

use App\Jobs\JobService;
use App\Models\Membership;
use App\Services\Documents\ExtractionService;
use App\Services\VaultService;
use App\Support\AccessContext;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Short-lived, single-purpose first-party upload link (used by the ChatGPT paystub intake flow). */
class IntakeController extends Controller
{
    private function link(Request $r, string $token): array
    {
        $link = DB::table('upload_links')->where('token_hash', hash('sha256', $token))->whereIn('purpose', ['paystub', 'paystub_copy'])->first();
        if (! $link || $link->used_at || now()->greaterThan($link->expires_at)) {
            abort(404);
        }
        $m = Membership::where('id', $link->membership_id)->where('user_id', $r->user()->id)->first();
        if (! $m || ! $m->isActive()) {
            abort(404);
        }
        $ctx = new AccessContext($r->user(), $m->workspace, $m);
        $context = json_decode((string) $link->context, true) ?: [];
        $emp = $ctx->constrain(DB::table('employments'), 'compensation', 'write')->where('id', $context['employment_id'] ?? '')->first();
        if (! $emp) {
            abort(404);
        }

        return [$link, $ctx, $emp];
    }

    public function show(Request $r, string $token)
    {
        [$link, , $emp] = $this->link($r, $token);

        return view('public.intake', ['employer' => $emp->employer_name, 'token' => $token, 'copy' => $link->purpose === 'paystub_copy']);
    }

    public function store(Request $r, VaultService $vault, JobService $jobs, string $token)
    {
        [$link, $ctx, $emp] = $this->link($r, $token);
        $r->validate(['file' => ['required', 'file', 'max:15360']]);
        app()->instance(AccessContext::class, $ctx);
        try {
            $stored = $vault->store($ctx, (string) file_get_contents($r->file('file')->getRealPath()), ['scope_id' => $emp->scope_id,
                'filename' => $r->file('file')->getClientOriginalName(), 'title' => 'Paystub — '.$emp->employer_name, 'doc_type' => 'paystub', 'origin' => 'mcp']);
        } catch (ApiError $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }
        $reviewUrl = url('/w/'.$ctx->workspace->id.'/review');
        if ($link->purpose === 'paystub_copy') {
            // Values were already provided by the assistant; the file is the paper copy, not something to extract.
            $extractionId = (string) ((json_decode((string) $link->context, true) ?: [])['extraction_id'] ?? '');
            app(ExtractionService::class)->attachCopy($extractionId, $stored);
            DB::table('upload_links')->where('token_hash', $link->token_hash)->update(['used_at' => now()]);

            return view('public.intake-done', ['reviewUrl' => url('/w/'.$ctx->workspace->id.'/extractions/'.$extractionId), 'copy' => true]);
        }
        $jobs->enqueue($ctx, 'document.extract', ['document_id' => $stored['document_id'], 'schema' => 'paystub', 'employment_id' => $emp->id,
            'client_provenance' => 'upload_link'], 'document', $stored['document_id'], $emp->scope_id, 120);
        DB::table('upload_links')->where('token_hash', $link->token_hash)->update(['used_at' => now(), 'draft_id' => $stored['document_id']]);
        DB::table('upload_links')->where('purpose', 'paystub_mcp_key')->where('context', 'like', '%'.$link->token_hash.'%')->update(['draft_id' => $stored['document_id']]);

        return view('public.intake-done', ['reviewUrl' => $reviewUrl, 'copy' => false]);
    }
}
