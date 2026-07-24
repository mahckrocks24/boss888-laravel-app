<?php

namespace App\Engines\Infrastructure\Models;

use App\Engines\Infrastructure\States\ProviderLifecycleState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A registered provider (Phase 2B-2).
 *
 * PLATFORM-LEVEL: no workspace_id, no BelongsToWorkspace. Which providers the
 * platform supports is not a tenant fact.
 *
 * `adapter_class` is $hidden. Exposing a PHP class name through an API leaks
 * internal structure and hands an attacker a target — the directive is explicit:
 * "Do not expose raw PHP class names through public APIs."
 */
class InfraProvider extends Model
{
    protected $table = 'infra_providers';

    protected $fillable = [
        'provider_key', 'display_name', 'provider_type', 'adapter_class',
        'adapter_version', 'lifecycle_state', 'enabled', 'production_ready',
        'sandbox_ready', 'priority', 'environments_json', 'regions_json',
        'currencies_json', 'feature_flags_json', 'metadata_json',
        'operational_notes', 'created_by_user_id',
    ];

    protected $casts = [
        'enabled'            => 'boolean',
        'production_ready'   => 'boolean',
        'sandbox_ready'      => 'boolean',
        'priority'           => 'integer',
        'environments_json'  => 'array',
        'regions_json'       => 'array',
        'currencies_json'    => 'array',
        'feature_flags_json' => 'array',
        'metadata_json'      => 'array',
    ];

    protected $hidden = ['adapter_class'];

    public const ENV_SANDBOX    = 'sandbox';
    public const ENV_PRODUCTION = 'production';

    public static function environments(): array
    {
        return [self::ENV_SANDBOX, self::ENV_PRODUCTION];
    }

    /**
     * IMMUTABLE IDENTIFIER (directive §2B-2: "immutable internal identifier").
     *
     * Enforced in the model rather than only by convention, because
     * `infra_provider_resources.provider` and every historical event store this
     * string. Renaming it would silently orphan every past mapping — the row
     * would still exist and simply stop matching anything.
     */
    protected static function booted(): void
    {
        static::updating(function (self $provider) {
            if ($provider->isDirty('provider_key')) {
                throw new RuntimeException(
                    'InfraProvider: provider_key is immutable once registered '
                    . '(historical provider_resources and events reference it).'
                );
            }
        });
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(InfraProviderCapability::class, 'provider_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(InfraProviderCredential::class, 'provider_id');
    }

    public function health(): HasMany
    {
        return $this->hasMany(InfraProviderHealth::class, 'provider_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(InfraProviderEvent::class, 'provider_id');
    }

    /** Technically registered AND switched on. Says nothing about health. */
    public function isEnabled(): bool
    {
        return (bool) $this->enabled
            && in_array($this->lifecycle_state, ProviderLifecycleState::acceptsNewWork(), true);
    }

    /**
     * Commercially selectable for the given environment.
     *
     * Production requires BOTH lifecycle `active` AND an explicit
     * production_ready assertion. Neither implies the other: a provider can be
     * live in sandbox (`active`) while production readiness is still unproven.
     */
    public function isSelectableFor(string $environment): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $envs = $this->environments_json ?? [];
        if ($envs && !in_array($environment, $envs, true)) {
            return false;
        }

        if ($environment === self::ENV_PRODUCTION) {
            return $this->production_ready
                && in_array($this->lifecycle_state, ProviderLifecycleState::productionSelectable(), true);
        }

        return $this->sandbox_ready
            && in_array($this->lifecycle_state, ProviderLifecycleState::acceptsNewWork(), true);
    }

    public function supportsRegion(?string $region): bool
    {
        if ($region === null) {
            return true;
        }

        $regions = $this->regions_json ?? [];

        // No declared regions = no regional constraint, not "no regions".
        return $regions === [] || in_array($region, $regions, true);
    }

    public function featureFlag(string $flag, bool $default = false): bool
    {
        return (bool) (($this->feature_flags_json ?? [])[$flag] ?? $default);
    }
}
