<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An append-only observation from a monitoring check (Phase 3A).
 *
 * Time series. Immutable: an observation of the past cannot be edited. Uptime
 * and incident history are computed from these rows, so a rewritable result
 * would corrupt every SLA number derived from it.
 */
class InfraMonitorResult extends Model
{
    use BelongsToWorkspace;

    protected $table = 'infra_monitor_results';

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'monitor_check_id', 'status', 'http_code', 'response_ms',
        'error_code', 'error_summary', 'probe_type', 'checked_at',
    ];

    protected $casts = [
        'http_code'   => 'integer',
        'response_ms' => 'integer',
        'checked_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('InfraMonitorResult is append-only.'));
        static::deleting(fn () => throw new RuntimeException('InfraMonitorResult is append-only.'));
    }
}
