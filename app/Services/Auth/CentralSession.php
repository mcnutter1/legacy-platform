<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

/** Local host-only session bound to a verified McNutt Cloud identity. */
class CentralSession
{
    private const KEY = 'central_auth';

    public function __construct(private readonly AuthGateway $gateway) {}

    public function establish(VerifiedIdentity $id): User
    {
        $user = User::firstOrNew([
            'central_principal_type' => $id->principalType,
            'central_principal_id' => $id->principalId,
        ]);
        if ($user->exists && $user->disabled_at) {
            throw new AuthRejected('disabled');
        }
        $user->fill([
            'email' => $id->email,
            'name' => $id->name,
            'username' => $id->username,
            'central_roles' => $id->roles,
            'last_login_at' => now(),
        ])->save();

        session()->invalidate();
        session()->regenerate(true);
        Auth::login($user);
        session()->put(self::KEY, [
            'principal_id' => $id->principalId,
            'token' => $id->sessionToken ? Crypt::encryptString($id->sessionToken) : null,
            'exp' => $id->expiresAt,
            'validated_at' => time(),
            'started_at' => time(),
        ]);
        Audit::log('auth.login', 'user', $user->id);

        return $user;
    }

    public function user(): ?User
    {
        return session(self::KEY) ? Auth::user() : null;
    }

    /** Revalidate if older than $maxAge. False = revoked/invalid. Throws AuthUnavailable during outages (fail closed). */
    public function ensureFresh(int $maxAge): bool
    {
        $data = session(self::KEY);
        $user = Auth::user();
        if (! $data || ! $user || $user->disabled_at) {
            return false;
        }
        $absolute = (int) config('legacy.auth.absolute_session_hours') * 3600;
        if (time() - (int) ($data['started_at'] ?? 0) > $absolute) {
            $this->forget();

            return false;
        }
        if (time() - (int) $data['validated_at'] < $maxAge && (int) $data['exp'] > time()) {
            return true;
        }
        $token = $data['token'] ? Crypt::decryptString($data['token']) : null;
        if (! $token) {
            return false;
        }
        $id = $this->gateway->revalidate($token);
        if (! $id || $id->principalId !== $data['principal_id'] || $id->principalType !== $user->central_principal_type) {
            $this->forget();

            return false;
        }
        $user->forceFill(['central_roles' => $id->roles, 'email' => $id->email, 'name' => $id->name])->save();
        $data['validated_at'] = time();
        $data['exp'] = $id->expiresAt;
        session()->put(self::KEY, $data);

        return true;
    }

    public function sessionToken(): ?string
    {
        $data = session(self::KEY);

        return ($data['token'] ?? null) ? Crypt::decryptString($data['token']) : null;
    }

    public function validatedAt(): ?int
    {
        return session(self::KEY)['validated_at'] ?? null;
    }

    public function forget(): void
    {
        Auth::logout();
        session()->forget(self::KEY);
        session()->invalidate();
        session()->regenerateToken();
    }
}
