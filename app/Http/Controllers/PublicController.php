<?php

namespace App\Http\Controllers;

use App\Services\ExportService;
use App\Services\VaultService;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicController extends Controller
{
    /** Authenticated SPA shell. Sensitive data is never embedded; the app fetches it from the API. */
    public function shell(Request $request)
    {
        return response()->view('shell', ['csrf' => csrf_token()])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Masked health: no versions of dependencies, counts or secrets. */
    public function health()
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'fail';
        }
        $vault = (string) config('legacy.vault.root');
        $checks['storage'] = is_dir($vault) && is_writable($vault) ? 'ok' : 'fail';
        $checks['vault_key'] = strlen((string) base64_decode((string) config('legacy.vault.root_key'), true)) === 32 ? 'ok' : 'fail';
        $py = (string) config('legacy.runner.python');
        $checks['runner'] = is_file($py) ? 'ok' : 'missing';
        $hb = DB::table('cache')->where('key', 'like', '%worker-heartbeat%')->max('expiration');
        $checks['worker'] = $hb && $hb > time() - 300 ? 'ok' : 'stale';
        $ok = ! in_array('fail', $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks, 'release' => substr((string) config('legacy.release'), 0, 12)], $ok ? 200 : 503);
    }

    public function download(Request $request, VaultService $vault, string $token)
    {
        try {
            $f = $vault->redeem($token, $request->user());
        } catch (ApiError) {
            abort(404);
        }

        return response($f['bytes'], 200, [
            'Content-Type' => $f['mime'],
            'Content-Disposition' => $f['disposition'].'; filename="'.$f['filename'].'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function export(ExportService $svc, string $token)
    {
        try {
            $g = $svc->lookup($token);
        } catch (ApiError) {
            return response()->view('public.export-unavailable', [], 404);
        }

        return view('public.export', ['name' => $g->recipient_name, 'expires' => $g->expires_at, 'token' => $token]);
    }

    public function exportVerify(Request $request, ExportService $svc, string $token)
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        try {
            $f = $svc->verifyAndDownload($token, (string) $request->input('code'), (string) $request->ip());
        } catch (ApiError $e) {
            if ($e->status === 404) {
                return response()->view('public.export-unavailable', [], 404);
            }

            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return response($f['bytes'], 200, ['Content-Type' => $f['mime'], 'Content-Disposition' => 'attachment; filename="'.$f['filename'].'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
