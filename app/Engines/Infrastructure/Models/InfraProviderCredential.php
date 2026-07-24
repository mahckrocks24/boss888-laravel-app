<?php

namespace App\Engines\Infrastructure\Models;

use App\Engines\Infrastructure\States\CredentialState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * 🔴 A provider credential (Phase 2B-3). The highest-risk model in INFRA888.
 *
 * DEFENCE IN DEPTH AGAINST SECRET LEAKAGE
 * ---------------------------------------
 * A single guard is not enough, because secrets escape through paths nobody
 * intended: a dd(), a Log::info($model), a JSON API response, a queued job
 * payload, an exception with model context attached. Each of the following
 * closes a DIFFERENT escape route:
 *
 *   1. `$hidden`                — blocks toArray()/toJson(), i.e. API responses.
 *   2. `encrypted` cast         — the DB column never holds plaintext at rest.
 *   3. toArray() override       — belt-and-braces if $hidden is ever edited away.
 *   4. __debugInfo()            — blocks var_dump()/dd()/dump().
 *   5. attributesToArray()      — blocks Log::info($model) and queue payload
 *                                 serialization, which do NOT call toArray().
 *   6. secret()                 — the ONLY read path, and it is explicit, so
 *                                 every access is greppable in review.
 *
 * Guards 4 and 5 are the ones usually missing in codebases that "hid" a secret
 * and still leaked it. `$hidden` alone does not protect a var_dump.
 *
 * WHY A FINGERPRINT EXISTS
 * SHA-256 of the secret, non-reversible. It answers two operational questions
 * without ever revealing the value: "is this the same token I issued?" and "did
 * rotation actually change the secret, or did someone paste the old one back?"
 * The second is a real rotation failure mode and is asserted in the tests.
 */
class InfraProviderCredential extends Model
{
    protected $table = 'infra_provider_credentials';

    protected $fillable = [
        'provider_id', 'credential_key', 'environment', 'label', 'purpose',
        'credential_role',
        'capability_scope_json', 'region_scope_json', 'account_identifier',
        'secret_encrypted', 'secret_fingerprint', 'secret_hint', 'state',
        'valid_from', 'expires_at', 'activated_at', 'revoked_at', 'revoked_reason',
        'supersedes_id', 'superseded_by_id', 'last_verified_at',
        'last_verification_ok', 'granted_capabilities_json',
        'missing_capabilities_json', 'last_verification_code',
        'last_verification_summary', 'created_by_user_id', 'activated_by_user_id',
        'revoked_by_user_id',
    ];

    /** Guard 1: never serialized to an API response. */
    protected $hidden = ['secret_encrypted', 'secret_fingerprint', 'secret_hint'];

    protected $casts = [
        // Guard 2: encrypted at rest via APP_KEY. There is no plaintext column.
        'secret_encrypted'          => 'encrypted',
        'capability_scope_json'     => 'array',
        'region_scope_json'         => 'array',
        'granted_capabilities_json' => 'array',
        'missing_capabilities_json' => 'array',
        'last_verification_ok'      => 'boolean',
        'valid_from'                => 'datetime',
        'expires_at'                => 'datetime',
        'activated_at'              => 'datetime',
        'revoked_at'                => 'datetime',
        'last_verified_at'          => 'datetime',
    ];

    private const SECRET_ATTRS = ['secret_encrypted', 'secret_fingerprint', 'secret_hint'];

    /** Guard 3. */
    public function toArray(): array
    {
        return $this->scrub(parent::toArray());
    }

    /**
     * Guard 5 — the one that catches Log::info($model) and queued-job payloads.
     * Neither calls toArray(); both reach attributesToArray().
     */
    public function attributesToArray(): array
    {
        return $this->scrub(parent::attributesToArray());
    }

    /** Guard 4 — var_dump() / dd() / dump(). */
    public function __debugInfo(): array
    {
        return $this->scrub($this->attributes);
    }

    private function scrub(array $data): array
    {
        foreach (self::SECRET_ATTRS as $attr) {
            if (array_key_exists($attr, $data)) {
                $data[$attr] = '[redacted]';
            }
        }

        return $data;
    }

    /**
     * Guard 6 — the ONLY sanctioned read path for secret material.
     *
     * Deliberately verbose and deliberately NOT an accessor: `$cred->secret`
     * would look like ordinary property access in review, whereas
     * `$cred->secret()` is greppable and obviously deliberate.
     *
     * Refuses to hand out an unusable credential. A revoked or superseded secret
     * must never reach a live call even if a caller asks for it — revocation that
     * can be bypassed by asking nicely is not revocation.
     */
    public function secret(): ?string
    {
        if (!in_array($this->state, CredentialState::usable(), true)) {
            throw new RuntimeException(
                "InfraProviderCredential {$this->id}: secret requested while state is "
                . "'{$this->state}'. Only " . implode('|', CredentialState::usable())
                . ' may be used for provider calls.'
            );
        }

        return $this->secret_encrypted;
    }

    /**
     * Read the secret for VERIFICATION only, bypassing the usable-state check.
     *
     * Necessary because a `pending_verification` credential must be testable —
     * that is the entire point of the state — but it is a separate, explicitly
     * named method so the bypass is visible rather than a flag on secret().
     */
    public function secretForVerification(): ?string
    {
        if (in_array($this->state, [CredentialState::REVOKED, CredentialState::SUPERSEDED], true)) {
            throw new RuntimeException(
                "InfraProviderCredential {$this->id}: '{$this->state}' credentials are terminal "
                . 'and may never be read, including for verification.'
            );
        }

        return $this->secret_encrypted;
    }

    public static function fingerprint(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function hint(string $secret): string
    {
        return strlen($secret) <= 4 ? '****' : '...' . substr($secret, -4);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(InfraProvider::class, 'provider_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function isUsable(): bool
    {
        return in_array($this->state, CredentialState::usable(), true);
    }

    /**
     * FAILS CLOSED on an empty scope.
     *
     * An unscoped credential is treated as covering NOTHING rather than
     * everything. The permissive reading is the classic wildcard-by-omission bug:
     * a credential created without a scope would quietly become the most powerful
     * one in the system.
     */
    public function coversCapability(string $capability): bool
    {
        $scope = $this->capability_scope_json ?? [];

        return $scope !== [] && in_array($capability, $scope, true);
    }

    public function coversRegion(?string $region): bool
    {
        if ($region === null) {
            return true;
        }

        $scope = $this->region_scope_json ?? [];

        // Unlike capability scope, an empty REGION scope means "not regionally
        // restricted" — regions are a filter on where a credential works, not a
        // grant of authority, so omission is not privilege escalation.
        return $scope === [] || in_array($region, $scope, true);
    }

    public function daysUntilExpiry(): ?int
    {
        if (!$this->expires_at) {
            return null;
        }

        return (int) floor(now()->diffInSeconds($this->expires_at, false) / 86400);
    }

    /** Metadata safe to return through an admin API. Never secret material. */
    public function toSafeArray(): array
    {
        return [
            'id'                    => $this->id,
            'credential_key'        => $this->credential_key,
            'environment'           => $this->environment,
            'label'                 => $this->label,
            'purpose'               => $this->purpose,
            'state'                 => $this->state,
            'capability_scope'      => $this->capability_scope_json ?? [],
            'region_scope'          => $this->region_scope_json ?? [],
            'account_identifier'    => $this->account_identifier,
            'hint'                  => $this->secret_hint,
            'fingerprint_short'     => $this->secret_fingerprint ? substr($this->secret_fingerprint, 0, 12) : null,
            'valid_from'            => optional($this->valid_from)->toIso8601String(),
            'expires_at'            => optional($this->expires_at)->toIso8601String(),
            'days_until_expiry'     => $this->daysUntilExpiry(),
            'activated_at'          => optional($this->activated_at)->toIso8601String(),
            'revoked_at'            => optional($this->revoked_at)->toIso8601String(),
            'revoked_reason'        => $this->revoked_reason,
            'last_verified_at'      => optional($this->last_verified_at)->toIso8601String(),
            'last_verification_ok'  => $this->last_verification_ok,
            'granted_capabilities'  => $this->granted_capabilities_json ?? [],
            'missing_capabilities'  => $this->missing_capabilities_json ?? [],
            'supersedes_id'         => $this->supersedes_id,
            'superseded_by_id'      => $this->superseded_by_id,
        ];
    }
}
