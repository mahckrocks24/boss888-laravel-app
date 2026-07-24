<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The canonical infrastructure asset (Phase 3B) — the single node type in the
 * one infrastructure graph. Every capability attaches here; nothing runs a
 * parallel hierarchy.
 *
 * An asset is a LIVING OBJECT: it carries health, risk, lifecycle, provider,
 * configuration and — through infra_asset_relationships — its place in the graph.
 * The rich existing tables (hosted_sites, hosting_accounts, monitor_checks) point
 * BACK to their asset via source_type/source_id; the asset does not duplicate them.
 */
class InfraAsset extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $table = 'infra_assets';

    protected $fillable = [
        'asset_uid', 'workspace_id', 'asset_type', 'name', 'management_mode',
        'lifecycle_state', 'health_state', 'health_checked_at', 'risk_state',
        'risk_reasons_json', 'provider_id', 'external_ref', 'source_type',
        'source_id', 'config_json', 'metadata_json', 'created_by',
    ];

    protected $casts = [
        'health_checked_at' => 'datetime',
        'risk_reasons_json' => 'array',
        'config_json'       => 'array',
        'metadata_json'     => 'array',
    ];

    // asset types
    public const TYPE_WEBSITE     = 'website';
    public const TYPE_DOMAIN      = 'domain';
    public const TYPE_DNS_ZONE    = 'dns_zone';
    public const TYPE_SERVER      = 'server';
    public const TYPE_SSL_CERT    = 'ssl_certificate';
    public const TYPE_DEPLOYMENT  = 'deployment';
    public const TYPE_MONITOR     = 'monitoring_check';
    public const TYPE_BACKUP      = 'backup';
    public const TYPE_EMAIL       = 'email_service';
    public const TYPE_PROVIDER    = 'provider';

    // management modes — the PTAA distinction, structural
    public const MODE_ADOPTED             = 'adopted';
    public const MODE_PROVISIONED         = 'provisioned';
    public const MODE_MANAGED_EXTERNALLY  = 'managed_externally';
    public const MODE_MANAGED_BY_INFRA888 = 'managed_by_infra888';

    // health / risk
    public const HEALTH_HEALTHY  = 'healthy';
    public const HEALTH_DEGRADED = 'degraded';
    public const HEALTH_DOWN     = 'down';
    public const HEALTH_UNKNOWN  = 'unknown';

    public const RISK_OK      = 'ok';
    public const RISK_WATCH   = 'watch';
    public const RISK_AT_RISK = 'at_risk';

    public static function assetTypes(): array
    {
        return [
            self::TYPE_WEBSITE, self::TYPE_DOMAIN, self::TYPE_DNS_ZONE, self::TYPE_SERVER,
            self::TYPE_SSL_CERT, self::TYPE_DEPLOYMENT, self::TYPE_MONITOR, self::TYPE_BACKUP,
            self::TYPE_EMAIL, self::TYPE_PROVIDER,
        ];
    }

    public static function managementModes(): array
    {
        return [self::MODE_ADOPTED, self::MODE_PROVISIONED, self::MODE_MANAGED_EXTERNALLY, self::MODE_MANAGED_BY_INFRA888];
    }

    /** Edges where this asset is the source. */
    public function outgoing(): HasMany
    {
        return $this->hasMany(InfraAssetRelationship::class, 'from_asset_id');
    }

    /** Edges where this asset is the target. */
    public function incoming(): HasMany
    {
        return $this->hasMany(InfraAssetRelationship::class, 'to_asset_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(InfraIncident::class, 'asset_id');
    }

    public function provider()
    {
        return $this->belongsTo(InfraProvider::class, 'provider_id');
    }

    /** Was this asset provisioned by INFRA888, or merely adopted/observed? */
    public function isProvisioned(): bool
    {
        return $this->management_mode === self::MODE_PROVISIONED;
    }

    public function isHealthy(): bool
    {
        return $this->health_state === self::HEALTH_HEALTHY;
    }

    public function isAtRisk(): bool
    {
        return $this->risk_state === self::RISK_AT_RISK;
    }
}
