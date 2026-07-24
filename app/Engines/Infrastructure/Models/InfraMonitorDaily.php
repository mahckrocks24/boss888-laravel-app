<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily rollup of monitor results (Phase 3B) — the retention/archival foundation.
 *
 * The raw time series (infra_monitor_results) is the WRITE path and grows to
 * millions of rows; it can be pruned after a short window. This daily rollup is
 * the long-term READ path for trends — uptime, latency percentiles, incident
 * counts — so historical intelligence survives without keeping every raw row
 * forever. The two scale independently.
 */
class InfraMonitorDaily extends Model
{
    use BelongsToWorkspace;

    protected $table = 'infra_monitor_daily';

    protected $fillable = [
        'workspace_id', 'monitor_check_id', 'day', 'checks', 'up', 'down', 'degraded',
        'uptime_pct', 'avg_response_ms', 'p95_response_ms', 'max_response_ms', 'incidents',
    ];

    protected $casts = [
        'day'             => 'date',
        'checks'          => 'integer',
        'up'              => 'integer',
        'down'            => 'integer',
        'degraded'        => 'integer',
        'uptime_pct'      => 'float',
        'avg_response_ms' => 'integer',
        'p95_response_ms' => 'integer',
        'max_response_ms' => 'integer',
        'incidents'       => 'integer',
    ];
}
