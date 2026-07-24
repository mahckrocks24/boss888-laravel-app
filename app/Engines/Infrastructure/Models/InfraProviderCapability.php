<?php

namespace App\Engines\Infrastructure\Models;

use App\Engines\Infrastructure\States\ProviderHealthState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One capability declaration for one provider in one environment (Phase 2B-2).
 *
 * THE CENTRAL RULE THIS MODEL ENFORCES
 * "A provider existing in the registry must not automatically mean all its
 * declared capabilities are enabled" (directive §2B-2).
 *
 * Hence `supported` and `enabled` are separate booleans with different meanings:
 *
 *   supported = the adapter implements this capability
 *   enabled   = we permit its use
 *
 * They are not redundant. The measured Cloudflare case is precisely a provider
 * that SUPPORTS certificate operations while our credential cannot perform them —
 * supported true, enabled false, and the platform must never route certificate
 * work there until that changes.
 */
class InfraProviderCapability extends Model
{
    protected $table = 'infra_provider_capabilities';

    protected $fillable = [
        'provider_id', 'capability', 'environment', 'supported', 'enabled',
        'implementation_version', 'health_state', 'regions_json',
        'constraints_json', 'feature_flags_json', 'notes',
    ];

    protected $casts = [
        'supported'          => 'boolean',
        'enabled'            => 'boolean',
        'regions_json'       => 'array',
        'constraints_json'   => 'array',
        'feature_flags_json' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(InfraProvider::class, 'provider_id');
    }

    /**
     * Operationally available = supported AND enabled AND health permits it.
     *
     * FAILS CLOSED on `unknown`: never having checked is not evidence of health.
     * ProviderHealthState::selectable() deliberately excludes it.
     */
    public function isOperational(): bool
    {
        return $this->supported
            && $this->enabled
            && in_array($this->health_state, ProviderHealthState::selectable(), true);
    }

    public function supportsRegion(?string $region): bool
    {
        if ($region === null) {
            return true;
        }

        $regions = $this->regions_json ?? [];

        return $regions === [] || in_array($region, $regions, true);
    }

    /** Non-secret operational limits the adapter should respect. */
    public function constraint(string $key, mixed $default = null): mixed
    {
        return ($this->constraints_json ?? [])[$key] ?? $default;
    }

    public function featureFlag(string $flag, bool $default = false): bool
    {
        return (bool) (($this->feature_flags_json ?? [])[$flag] ?? $default);
    }
}
