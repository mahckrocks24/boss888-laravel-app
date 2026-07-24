<?php

namespace App\Engines\Infrastructure\Models;

use App\Engines\Infrastructure\States\ProviderHealthState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Observed health of a provider, or of one capability of one provider (2B-4).
 *
 * `capability` NULL = the provider-level rollup row.
 *
 * NOTHING HERE MAY TOUCH COMMERCIAL STATE.
 * There is no relation to InfraSubscription, no billing field, and
 * ProviderHealthService writes to no commercial table. Provider unavailable is
 * not customer suspended; credential expired is not subscription cancelled.
 * Keeping the two apart is a data-model decision, not a convention to remember.
 */
class InfraProviderHealth extends Model
{
    protected $table = 'infra_provider_health';

    protected $fillable = [
        'provider_id', 'capability', 'environment', 'health_state', 'auth_ok',
        'api_available', 'latency_ms', 'quota_state', 'quota_remaining',
        'quota_limit', 'quota_resets_at', 'rate_limited_until', 'maintenance_until',
        'account_restriction', 'last_success_at', 'last_failure_at',
        'consecutive_failures', 'last_error_code', 'last_error_summary',
        'checked_at', 'probe_type', 'checked_by_user_id', 'credential_id',
        'credential_expires_at', 'diagnostics_json',
    ];

    protected $casts = [
        'auth_ok'               => 'boolean',
        'api_available'         => 'boolean',
        'latency_ms'            => 'integer',
        'quota_remaining'       => 'integer',
        'quota_limit'           => 'integer',
        'consecutive_failures'  => 'integer',
        'quota_resets_at'       => 'datetime',
        'rate_limited_until'    => 'datetime',
        'maintenance_until'     => 'datetime',
        'last_success_at'       => 'datetime',
        'last_failure_at'       => 'datetime',
        'checked_at'            => 'datetime',
        'credential_expires_at' => 'datetime',
        'diagnostics_json'      => 'array',
    ];

    public const PROBE_PASSIVE = 'passive';
    public const PROBE_ACTIVE  = 'active';
    public const PROBE_MANUAL  = 'manual';

    public const QUOTA_OK        = 'ok';
    public const QUOTA_WARNING   = 'warning';
    public const QUOTA_EXHAUSTED = 'exhausted';

    public function provider(): BelongsTo
    {
        return $this->belongsTo(InfraProvider::class, 'provider_id');
    }

    public function isProviderLevel(): bool
    {
        return $this->capability === null;
    }

    /** May this be selected for real work right now? */
    public function isSelectable(): bool
    {
        if (!in_array($this->health_state, ProviderHealthState::selectable(), true)) {
            return false;
        }

        // A live rate-limit window overrides an otherwise healthy verdict: the
        // stored state may simply predate the limit being hit.
        if ($this->rate_limited_until && $this->rate_limited_until->isFuture()) {
            return false;
        }

        if ($this->maintenance_until && $this->maintenance_until->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * Whether a failed call against this provider is worth retrying.
     *
     * Exists to prevent retry storms (directive §2B-4). Retrying an
     * `unauthorized` result cannot succeed — no number of attempts creates
     * permission — so the caller must escalate to a human instead of looping.
     */
    public function isRetryable(): bool
    {
        if (in_array($this->health_state, ProviderHealthState::permanentFailure(), true)) {
            return false;
        }

        return in_array($this->health_state, ProviderHealthState::temporaryFailure(), true)
            || $this->health_state === ProviderHealthState::HEALTHY
            || $this->health_state === ProviderHealthState::DEGRADED;
    }

    /** When a retryable failure should next be attempted. Null = no constraint. */
    public function retryNotBefore(): ?\Illuminate\Support\Carbon
    {
        $candidates = array_filter([
            $this->rate_limited_until,
            $this->maintenance_until,
            $this->quota_state === self::QUOTA_EXHAUSTED ? $this->quota_resets_at : null,
        ]);

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->max();
    }
}
