<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Services\Auth\StepUp;
use App\Services\Mcp\OAuthService;
use App\Support\ApiError;
use Illuminate\Http\Request;

class OAuthController extends Controller
{
    public function __construct(private readonly OAuthService $oauth) {}

    public function protectedResource()
    {
        return response()->json([
            'resource' => $this->oauth->resource(),
            'authorization_servers' => [url('/')],
            'scopes_supported' => array_keys(OAuthService::SCOPES),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'McNutt Legacy',
            'resource_documentation' => url('/docs/mcp'),
        ]);
    }

    public function authorizationServer()
    {
        return response()->json([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/register'),
            'revocation_endpoint' => url('/oauth/revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => array_keys(OAuthService::SCOPES),
        ]);
    }

    public function register(Request $r)
    {
        try {
            return response()->json($this->oauth->register($r->json()->all()), 201);
        } catch (ApiError $e) {
            return response()->json(['error' => $e->errorCode, 'error_description' => $e->getMessage()], $e->status);
        }
    }

    private function validateAuthorizeRequest(Request $r): array
    {
        $client = $this->oauth->client((string) $r->input('client_id'));
        $redirect = (string) $r->input('redirect_uri');
        if (! in_array($redirect, json_decode((string) $client->redirect_uris, true) ?: [], true)) {
            throw new ApiError('invalid_request', 'redirect_uri is not registered for this client.', 400);
        }
        if ($r->input('response_type') !== 'code' || $r->input('code_challenge_method') !== 'S256' || strlen((string) $r->input('code_challenge')) < 43) {
            throw new ApiError('invalid_request', 'Authorization code flow with PKCE S256 is required.', 400);
        }
        $resource = $r->input('resource');
        if ($resource !== null && $resource !== $this->oauth->resource()) {
            throw new ApiError('invalid_target', 'Unknown resource.', 400);
        }

        return [$client, $redirect];
    }

    public function authorize(Request $r)
    {
        try {
            [$client, $redirect] = $this->validateAuthorizeRequest($r);
        } catch (ApiError $e) {
            return response()->view('errors.access-denied', ['reason' => $e->getMessage()], 400);
        }

        return $this->consent($r, $client, $redirect);
    }

    /** Browsers apply CSP form-action to the post-submit redirect, so the registered client origin must be allowed. */
    private function consent(Request $r, object $client, string $redirect, array $errors = [], int $status = 200)
    {
        $p = parse_url($redirect);
        $r->attributes->set('csp_form_action', ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : ''));
        $memberships = Membership::with('workspace')->where('user_id', $r->user()->id)->where('status', 'active')->get();

        return response()->view('oauth.consent', [
            'client' => $client, 'scopes' => array_intersect_key(OAuthService::SCOPES, array_flip($this->oauth->parseScopes($r->input('scope')))),
            'memberships' => $memberships, 'params' => $r->only(['client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource', 'response_type']),
            'stepupFresh' => StepUp::isFresh(), 'stepupEnrolled' => (bool) $r->user()->totp_enabled_at,
            'consentErrors' => $errors, 'selectedMembership' => $r->input('membership_id'), 'selectedScopes' => $r->has('scopes') ? (array) $r->input('scopes') : null,
        ], $status);
    }

    public function approve(Request $r)
    {
        try {
            [$client, $redirect] = $this->validateAuthorizeRequest($r);
        } catch (ApiError $e) {
            return response()->view('errors.access-denied', ['reason' => $e->getMessage()], 400);
        }
        $sep = str_contains($redirect, '?') ? '&' : '?';
        if ($r->input('decision') !== 'allow') {
            return redirect()->away($redirect.$sep.http_build_query(['error' => 'access_denied', 'state' => $r->input('state'), 'iss' => url('/')]));
        }
        if (! StepUp::isFresh()) {
            $code = (string) $r->input('totp');
            if (! $r->user()->totp_enabled_at || ! StepUp::verify($r->user(), $code)) {
                return $this->consent($r, $client, $redirect, ['That authenticator code did not match. Enter the current 6-digit code.'], 422);
            }
            StepUp::markVerified();
        }
        $m = Membership::where('user_id', $r->user()->id)->where('id', (string) $r->input('membership_id'))->where('status', 'active')->first();
        if (! $m) {
            return $this->consent($r, $client, $redirect, ['Choose a workspace.'], 422);
        }
        $granted = array_values(array_intersect($this->oauth->parseScopes($r->input('scope')), (array) $r->input('scopes', [])));
        if (! $granted) {
            return $this->consent($r, $client, $redirect, ['Select at least one permission.'], 422);
        }
        $code = $this->oauth->issueCode($client, $m, $granted, $redirect, (string) $r->input('code_challenge'), $r->input('resource'));

        return redirect()->away($redirect.$sep.http_build_query(['code' => $code, 'state' => $r->input('state'), 'iss' => url('/')]));
    }

    public function token(Request $r)
    {
        try {
            $d = $r->all();
            if (preg_match('/^Basic\s+(.+)$/i', (string) $r->header('Authorization'), $m)) {
                // RFC 6749 §2.3.1: credentials are form-urlencoded before base64.
                [$id, $secret] = array_pad(explode(':', (string) base64_decode($m[1], true), 2), 2, '');
                $d['client_id'] = urldecode($id);
                $d['client_secret'] = urldecode($secret);
            }
            $out = match ($d['grant_type'] ?? '') {
                'authorization_code' => $this->oauth->exchangeCode($d),
                'refresh_token' => $this->oauth->refresh($d),
                default => throw new ApiError('unsupported_grant_type', 'Unsupported grant type.', 400),
            };

            return response()->json($out, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (ApiError $e) {
            return response()->json(['error' => $e->errorCode, 'error_description' => $e->getMessage()], $e->status, ['Cache-Control' => 'no-store']);
        }
    }

    public function revoke(Request $r)
    {
        $this->oauth->revoke((string) $r->input('token'));

        return response()->json([], 200);
    }
}
