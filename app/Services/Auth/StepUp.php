<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\ApiError;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Application step-up (RFC 6238 TOTP) for privileged actions, layered on a fresh central revalidation.
 * Upstream MFA is not asserted by the McNutt Cloud payload, so the app verifies its own factor.
 */
final class StepUp
{
    private const SESSION_KEY = 'stepup_at';

    public static function newSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function otpauthUri(User $user, string $secret): string
    {
        $issuer = (string) config('legacy.stepup.issuer');
        $label = rawurlencode($issuer.':'.($user->email ?? $user->username ?? $user->id));

        return "otpauth://totp/{$label}?secret={$secret}&issuer=".rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public static function verify(User $user, string $code, ?string $secret = null): bool
    {
        $secret ??= $user->totp_secret;
        $key = 'stepup:'.$user->id;
        if (! $secret || ! preg_match('/^\d{6}$/', $code) || RateLimiter::tooManyAttempts($key, 5)) {
            RateLimiter::hit($key, 300);

            return false;
        }
        $bin = self::base32Decode($secret);
        $now = intdiv(time(), 30);
        foreach ([-1, 0, 1] as $offset) {
            $counter = $now + $offset;
            if (hash_equals(self::code($bin, $counter), $code)) {
                // Each time-step code can be used once.
                if (! Cache::add('stepup-used:'.$user->id.':'.$counter, 1, 120)) {
                    return false;
                }
                RateLimiter::clear($key);

                return true;
            }
        }
        RateLimiter::hit($key, 300);

        return false;
    }

    public static function code(string $secretBin, int $counter): string
    {
        $h = hash_hmac('sha1', pack('N*', 0, $counter), $secretBin, true);
        $o = ord($h[19]) & 0xF;
        $v = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);

        return str_pad((string) ($v % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public static function markVerified(): void
    {
        session()->put(self::SESSION_KEY, time());
        Audit::log('auth.stepup.verified');
    }

    public static function isFresh(): bool
    {
        if (! config('legacy.stepup.required')) {
            return true;
        }
        $at = (int) session(self::SESSION_KEY, 0);

        return $at > 0 && time() - $at < (int) config('legacy.stepup.ttl_seconds');
    }

    public static function require(): void
    {
        if (! self::isFresh()) {
            throw new ApiError('step_up_required', 'Confirm with your authenticator code to continue.', 403);
        }
    }

    public static function base32(string $bin): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
            $p = strpos($alphabet, $c);
            if ($p === false) {
                continue;
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
