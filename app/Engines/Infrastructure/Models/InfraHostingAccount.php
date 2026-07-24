<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\States\HostingState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A hosting SERVICE ENTITLEMENT — not a server (directive §16).
 *
 * There is intentionally no server/droplet/IP attribute. Where this account
 * physically runs is an InfraProviderResource, so the entitlement survives a
 * provider or host migration untouched.
 */
class InfraHostingAccount extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $table = 'infra_hosting_accounts';

    protected $fillable = [
        'asset_id', 'workspace_id', 'subscription_id', 'name', 'state', 'previous_state',
        'region', 'environment', 'allowed_sites', 'allowed_storage_mb',
        'allowed_bandwidth_mb', 'current_storage_mb', 'current_bandwidth_mb',
        'usage_synced_at', 'backup_state', 'last_backup_at', 'health_state',
        'health_checked_at', 'provisioned_at', 'suspended_at', 'terminated_at',
        'created_by', 'metadata_json',
    ];

    protected $casts = [
        'allowed_sites'        => 'integer',
        'allowed_storage_mb'   => 'integer',
        'allowed_bandwidth_mb' => 'integer',
        'current_storage_mb'   => 'integer',
        'current_bandwidth_mb' => 'integer',
        'usage_synced_at'      => 'datetime',
        'last_backup_at'       => 'datetime',
        'health_checked_at'    => 'datetime',
        'provisioned_at'       => 'datetime',
        'suspended_at'         => 'datetime',
        'terminated_at'        => 'datetime',
        'metadata_json'        => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(InfraSubscription::class, 'subscription_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(InfraHostedSite::class, 'hosting_account_id');
    }

    public function isOperational(): bool
    {
        return in_array($this->state, HostingState::operational(), true);
    }

    public function storageUsagePercent(): ?int
    {
        if (!$this->allowed_storage_mb) {
            return null;
        }

        return (int) min(100, round(($this->current_storage_mb / $this->allowed_storage_mb) * 100));
    }

    public function bandwidthUsagePercent(): ?int
    {
        if (!$this->allowed_bandwidth_mb) {
            return null;
        }

        return (int) min(100, round(($this->current_bandwidth_mb / $this->allowed_bandwidth_mb) * 100));
    }

    /**
     * @throws \InvalidArgumentException on an illegal transition.
     */
    public function transitionTo(string $to): self
    {
        HostingState::assertTransition((string) $this->state, $to);

        $this->previous_state = $this->state;
        $this->state = $to;

        return $this;
    }
}
