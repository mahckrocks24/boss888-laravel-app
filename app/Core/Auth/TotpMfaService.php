<?php

namespace App\Core\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TOTP MFA for privileged users (Phase 2B-R1, WS10 — RFC 6238).
 *
 * WHY SELF-IMPLEMENTED RATHER THAN A PACKAGE: TOTP verification is ~40 lines of
 * standard HMAC, and this platform uses custom JWT auth rather than
 * Fortify/Jetstream (which would otherwise supply it). Pulling in an auth
 * framework to get one algorithm would be a far larger and riskier change than
 * the directive's "do not implement an overbroad authentication rewrite" allows.
 *
 * 🔴 SECRET HANDLING
 *   - the TOTP seed is stored ONLY through the `encrypted` cast (no plaintext column)
 *   - recovery codes are stored HASHED (bcrypt), never recoverable, one-time use
 *   - enrolment is two-step: enrol() generates and stores a seed but leaves MFA
 *     DISABLED; confirm() enables it only after the user proves one valid code,
 *     so a mistyped seed cannot lock an admin out.
 *
 * STANDING STILL BY DEFAULT: no user is enrolled by this phase. This is the
 * mechanism, deployed inert.
 */
class TotpMfaService
{
    private const PERIOD    = 30;   // seconds per TOTP window
    private const DIGITS    = 6;
    private const ALGO      = 'sha1';
    private const DRIFT     = 1;    // accept the adjacent window each side
    private const RECOVERY_COUNT = 10;

    /**
     * Begin enrolment. Generates a seed, stores it encrypted, but leaves MFA
     * DISABLED until confirm() succeeds. Returns the seed + provisioning URI so
     * the caller can render a QR — the ONLY time the seed leaves this service.
     *
     * @return array{secret:string,otpauth_uri:string,recovery_codes:array<int,string>}
     */
    public function enrol(User $user, string $issuer = 'LevelUp Growth'): array
    {
        $secret = $this->generateSecret();

        // Plaintext recovery codes are returned ONCE here; only their hashes are
        // stored. If the user loses them, they regenerate — they are never
        // readable from the database.
        $plainCodes  = $this->generateRecoveryCodes();
        $hashedCodes = array_map(fn ($c) => Hash::make($c), $plainCodes);

        $user->forceFill([
            'mfa_secret_encrypted'         => $secret,
            'mfa_recovery_codes_encrypted' => json_encode($hashedCodes),
            'mfa_enabled'                  => false,   // not yet — confirm() enables
            'mfa_confirmed_at'             => null,
        ])->save();

        $label = rawurlencode($issuer) . ':' . rawurlencode($user->email);
        $uri = sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            $label, $secret, rawurlencode($issuer),
            strtoupper(self::ALGO), self::DIGITS, self::PERIOD
        );

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'recovery_codes' => $plainCodes];
    }

    /**
     * Confirm enrolment with a live code. Only now is MFA enabled.
     */
    public function confirm(User $user, string $code): bool
    {
        if (!$user->mfa_secret_encrypted) {
            throw new RuntimeException('No MFA enrolment in progress for this user.');
        }

        if (!$this->verifyTotp($user->mfa_secret_encrypted, $code)) {
            return false;
        }

        $user->forceFill([
            'mfa_enabled'          => true,
            'mfa_confirmed_at'     => now(),
            'mfa_last_verified_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Verify a factor at authentication or step-up time.
     *
     * Accepts a TOTP code OR a one-time recovery code. On success, stamps the
     * freshness anchor used by step-up. Machine identities cannot reach here —
     * there is no code an API key can present.
     */
    public function verify(User $user, string $code): bool
    {
        if (!$user->mfa_enabled || !$user->mfa_secret_encrypted) {
            return false;
        }

        if ($this->verifyTotp($user->mfa_secret_encrypted, $code)
            || $this->consumeRecoveryCode($user, $code)) {
            $user->forceFill(['mfa_last_verified_at' => now()])->save();
            return true;
        }

        return false;
    }

    /**
     * Has this user proved a second factor within $maxAgeSeconds?
     *
     * The step-up gate. A privileged operation demands a RECENT factor, not merely
     * that MFA is enabled — a session hijacked hours after login must not inherit
     * activation authority.
     */
    public function hasFreshVerification(User $user, int $maxAgeSeconds = 900): bool
    {
        if (!$user->mfa_enabled || !$user->mfa_last_verified_at) {
            return false;
        }

        return $user->mfa_last_verified_at->diffInSeconds(now()) <= $maxAgeSeconds;
    }

    /** RFC 6238 verification with bounded drift. */
    public function verifyTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = (int) floor(time() / self::PERIOD);

        for ($i = -self::DRIFT; $i <= self::DRIFT; $i++) {
            if (hash_equals($this->hotp($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    private function hotp(string $base32Secret, int $counter): string
    {
        $key = $this->base32Decode($base32Secret);
        $bin = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac(self::ALGO, $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7fffffff;

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = json_decode($user->mfa_recovery_codes_encrypted ?: '[]', true) ?: [];
        $code  = trim($code);

        foreach ($codes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                // One-time: remove it so a captured code cannot be replayed.
                unset($codes[$i]);
                $user->forceFill([
                    'mfa_recovery_codes_encrypted' => json_encode(array_values($codes)),
                ])->save();
                return true;
            }
        }

        return false;
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $plain  = $this->generateRecoveryCodes();
        $hashed = array_map(fn ($c) => Hash::make($c), $plain);

        $user->forceFill(['mfa_recovery_codes_encrypted' => json_encode($hashed)])->save();

        return $plain;
    }

    private function generateSecret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // base32
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }
        return $out;
    }

    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $codes[] = strtoupper(Str::random(5) . '-' . Str::random(5));
        }
        return $codes;
    }

    private function base32Decode(string $b32): string
    {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = rtrim(strtoupper($b32), '=');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos($map, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }
        return $bytes;
    }
}
