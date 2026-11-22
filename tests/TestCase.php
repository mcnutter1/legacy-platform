<?php

namespace Tests;

use App\Models\Membership;
use App\Models\Scope;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AccessService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected string $vaultRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vaultRoot = sys_get_temp_dir().'/legacy-test-'.Str::random(10);
        config([
            'legacy.auth.workspace_creators' => ['*'],
            'legacy.stepup.required' => true,
            'legacy.vault.root' => $this->vaultRoot.'/vault',
            'legacy.vault.job_dir' => $this->vaultRoot.'/jobs',
            'legacy.vault.root_key' => base64_encode(str_repeat("\x07", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            'legacy.vault.root_key_id' => 'test-k1',
            'legacy.vault.scanner' => 'none',
            'legacy.notifications.driver' => 'log',
            'legacy.openai.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultRoot);
        parent::tearDown();
    }

    protected function makeUser(string $uid, string $name = 'Test User'): User
    {
        return User::create(['central_principal_type' => 'user', 'central_principal_id' => $uid, 'name' => $name, 'email' => $uid.'@example.test']);
    }

    /** Simulate an established McNutt Cloud session (and optionally a fresh step-up). */
    protected function signIn(User $user, bool $stepUp = true): static
    {
        $session = ['central_auth' => ['principal_id' => $user->central_principal_id, 'token' => null, 'exp' => time() + 3600, 'validated_at' => time(), 'started_at' => time()]];
        if ($stepUp) {
            $session['stepup_at'] = time();
        }

        return $this->actingAs($user)->withSession($session);
    }

    /** @return array{0: Workspace, 1: Membership, 2: Scope, 3: Scope} workspace, admin membership, joint scope, private scope */
    protected function makeWorkspace(User $owner, string $name = 'Test Family'): array
    {
        $ws = app(AccessService::class)->createWorkspace($owner, $name);
        $m = Membership::where('workspace_id', $ws->id)->where('user_id', $owner->id)->firstOrFail();
        $joint = Scope::where('workspace_id', $ws->id)->where('kind', 'joint')->firstOrFail();
        $private = Scope::where('workspace_id', $ws->id)->where('kind', 'private')->firstOrFail();

        return [$ws, $m, $joint, $private];
    }

    protected function addMember(Workspace $ws, User $user, string $role = 'member'): Membership
    {
        return Membership::create(['workspace_id' => $ws->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'label' => $user->name]);
    }

    protected function wsUrl(Workspace|string $ws, string $path): string
    {
        $id = $ws instanceof Workspace ? $ws->id : $ws;

        return "/api/v1/workspaces/{$id}/".ltrim($path, '/');
    }

    /** JSON mutation with a fresh Idempotency-Key unless one is supplied. */
    protected function send(string $method, string $uri, array $data = [], array $headers = [])
    {
        $headers += ['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'];

        return $this->json($method, $uri, $data, $headers);
    }
}
