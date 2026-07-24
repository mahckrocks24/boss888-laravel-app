<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * A down period for a monitored target (Phase 3A).
 *
 * Opened when a check first transitions to down; closed when it recovers. This
 * is what an SLA claim rests on — "how long was it down, and when" — and it
 * cannot be reconstructed from point-in-time status alone.
 */
class InfraMonitorIncident extends Model
{
    use BelongsToWorkspace;

    protected $table = 'infra_monitor_incidents';

    protected $fillable = [
        'workspace_id', 'monitor_check_id', 'started_at', 'resolved_at',
        'duration_seconds', 'cause', 'failure_count', 'state',
    ];

    protected $casts = [
        'started_at'       => 'datetime',
        'resolved_at'      => 'datetime',
        'duration_seconds' => 'integer',
        'failure_count'    => 'integer',
    ];

    public const STATE_OPEN     = 'open';
    public const STATE_RESOLVED = 'resolved';

    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }
}
