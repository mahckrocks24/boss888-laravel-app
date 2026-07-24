<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A configured monitoring check for one target (Phase 3A).
 *
 * Tenant-scoped: PTAA's checks are isolated from every other workspace's exactly
 * like every other INFRA888 record, via the BelongsToWorkspace global scope.
 */
class InfraMonitorCheck extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $table = 'infra_monitor_checks';

    protected $fillable = [
        'asset_id', 'workspace_id', 'hosted_site_id', 'name', 'check_type', 'target_url',
        'expected_status', 'interval_seconds', 'timeout_seconds', 'enabled',
        'last_status', 'last_response_ms', 'last_http_code', 'last_checked_at',
        'consecutive_failures', 'down_since', 'monitor_provider', 'metadata_json',
    ];

    protected $casts = [
        'enabled'              => 'boolean',
        'expected_status'      => 'integer',
        'interval_seconds'     => 'integer',
        'timeout_seconds'      => 'integer',
        'last_response_ms'     => 'integer',
        'last_http_code'       => 'integer',
        'consecutive_failures' => 'integer',
        'last_checked_at'      => 'datetime',
        'down_since'           => 'datetime',
        'metadata_json'        => 'array',
    ];

    public const STATUS_UP       = 'up';
    public const STATUS_DOWN     = 'down';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNKNOWN  = 'unknown';

    public function results(): HasMany
    {
        return $this->hasMany(InfraMonitorResult::class, 'monitor_check_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(InfraMonitorIncident::class, 'monitor_check_id');
    }

    public function site()
    {
        return $this->belongsTo(InfraHostedSite::class, 'hosted_site_id');
    }

    public function isDown(): bool
    {
        return $this->last_status === self::STATUS_DOWN;
    }
}
