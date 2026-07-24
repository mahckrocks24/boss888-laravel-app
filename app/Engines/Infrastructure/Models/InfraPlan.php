<?php

namespace App\Engines\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A versioned, priced package. Catalog data — not workspace-owned.
 *
 * Plans are NEVER edited in place once subscribed to. Publishing a change means
 * inserting version+1 and flipping is_current on the old row, so existing
 * subscribers keep the terms they bought (directive §13).
 *
 * All money is integer minor units + explicit currency. There are no float prices
 * and no currency-less amounts anywhere in INFRA888.
 */
class InfraPlan extends Model
{
    protected $table = 'infra_plans';

    protected $fillable = [
        'product_id', 'slug', 'name', 'version', 'is_current', 'is_public',
        'currency', 'recurring_amount_minor', 'billing_period',
        'setup_fee_minor', 'migration_fee_minor', 'renewal_amount_minor',
        'included_sites', 'included_storage_mb', 'included_bandwidth_mb',
        'included_mailboxes', 'included_domains', 'backup_retention_days',
        'overage_storage_minor_per_gb', 'overage_bandwidth_minor_per_gb',
        'features_json', 'metadata_json',
        // Phase 2A-1 commercial catalog
        'lifecycle_status', 'successor_plan_id', 'sort_order', 'region',
        'allows_monthly', 'allows_annual', 'allows_triennial',
        'included_staging_environments', 'included_restores_per_month',
        'included_migrations', 'compute_class', 'monitoring_level', 'support_tier',
        // Phase 2A-2 immutability + lineage.
        'published_at', 'frozen_at', 'withdrawn_at', 'derived_from_plan_id', 'created_by',
    ];

    protected $casts = [
        'version'                       => 'integer',
        'is_current'                    => 'boolean',
        'is_public'                     => 'boolean',
        'recurring_amount_minor'        => 'integer',
        'setup_fee_minor'               => 'integer',
        'migration_fee_minor'           => 'integer',
        'renewal_amount_minor'          => 'integer',
        'included_sites'                => 'integer',
        'included_storage_mb'           => 'integer',
        'included_bandwidth_mb'         => 'integer',
        'included_mailboxes'            => 'integer',
        'included_domains'              => 'integer',
        'backup_retention_days'         => 'integer',
        'overage_storage_minor_per_gb'  => 'integer',
        'overage_bandwidth_minor_per_gb'=> 'integer',
        'published_at'                  => 'datetime',
        'frozen_at'                     => 'datetime',
        'withdrawn_at'                  => 'datetime',
        'features_json'                 => 'array',
        'metadata_json'                 => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(InfraProduct::class, 'product_id');
    }

    /** Supplemental entitlements attached to this plan version. */
    public function entitlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InfraPlanEntitlement::class, 'plan_id');
    }

    /** Which billing terms this plan may be purchased on. */
    public function availableTerms(): array
    {
        $terms = [];
        if ($this->allows_monthly)   { $terms[] = 'monthly'; }
        if ($this->allows_annual)    { $terms[] = 'annual'; }
        if ($this->allows_triennial) { $terms[] = 'triennial'; }

        return $terms;
    }

    /** Renewal price defaults to the recurring price unless explicitly overridden. */
    public function renewalAmountMinor(): int
    {
        return $this->renewal_amount_minor ?? $this->recurring_amount_minor;
    }
}
