<?php

namespace App\Http\Controllers;

use App\Services\AccessService;
use App\Services\Auth\AuthGateway;
use App\Services\Auth\AuthRejected;
use App\Services\Auth\AuthUnavailable;
use App\Services\Auth\CentralSession;
use App\Services\Auth\DevAuthGateway;
use App\Support\ApiError;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(private readonly AuthGateway $gateway, private readonly CentralSession $session) {}

    public function login()
    {
        if ($this->session->user()) {
            return redirect('/');
        }
        if (! $this->gateway instanceof DevAuthGateway && (! config('legacy.auth.app_id') || ! config('legacy.auth.app_secret'))) {
            return response()->view('errors.auth-unconfigured', [], 503);
        }

        return view('auth.login', ['loginUrl' => $this->gateway->loginUrl(url('/auth/callback'))]);
    }

    public function callback(Request $request)
    {
        try {
            $identity = $this->gateway->verifyCallback($request->query());
            $this->session->establish($identity);
        } catch (AuthRejected $e) {
            Log::notice('auth.callback.rejected', ['reason' => $e->getMessage()]);
            if ($denied = $this->gateway->accessDeniedUrl($e->getMessage(), url('/'))) {
                return redirect()->away($denied);
            }

            return response()->view('errors.access-denied', ['reason' => 'Sign-in could not be verified. Please try again.'], 403);
        } catch (AuthUnavailable) {
            return response()->view('errors.auth-unavailable', [], 503);
        }
        $intended = session()->pull('url.intended', '/');
        if (! is_string($intended) || ! str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            $intended = '/';
        }

        // Immediate redirect removes payload/sig from the address bar and history.
        return redirect($intended);
    }

    public function logout()
    {
        $token = $this->session->sessionToken();
        if ($this->session->user()) {
            Audit::log('auth.logout', 'user', $this->session->user()->id);
        }
        $this->session->forget();

        return redirect()->away($this->gateway->logoutUrl(url('/'), $token));
    }

    public function devForm()
    {
        abort_unless($this->gateway instanceof DevAuthGateway && ! app()->environment('production'), 404);

        return view('auth.dev-login');
    }

    public function devLogin(Request $request)
    {
        abort_unless($this->gateway instanceof DevAuthGateway && ! app()->environment('production'), 404);
        $data = $request->validate(['uid' => ['required', 'regex:/^[A-Za-z0-9_-]{3,40}$/'], 'name' => ['required', 'string', 'max:80'], 'email' => ['required', 'email']]);
        $q = DevAuthGateway::sign(['iss' => 'dev', 'iat' => time(), 'exp' => time() + 3600, 'principal' => ['type' => 'user', 'id' => $data['uid']],
            'identity' => ['uid' => $data['uid'], 'email' => $data['email'], 'name' => $data['name'], 'username' => $data['uid']], 'roles' => ['user']]);

        return redirect('/auth/callback?'.http_build_query($q));
    }

    public function invite(Request $request, AccessService $svc, string $token)
    {
        $user = $this->session->user();
        if (! $user) {
            $request->session()->put('url.intended', '/invite/'.$token);

            return redirect('/auth/login');
        }
        try {
            $m = $svc->acceptInvite($user, $token);
        } catch (ApiError $e) {
            return response()->view('errors.access-denied', ['reason' => $e->getMessage()], $e->status);
        }

        return view('auth.invite-accepted', ['pending' => $m->status === 'pending_approval']);
    }
}
