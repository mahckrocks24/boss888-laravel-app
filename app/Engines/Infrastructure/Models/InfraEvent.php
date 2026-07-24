<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only infrastructure history.
 *
 * Exists because the platform audit path is insufficient for infrastructure
 * (Phase 0 audit §7): EngineExecutionService.php:304-306 logs array_keys($params)
 * only — no values — and denials/failures return early before the audit write.
 *
 * This model records before/after state, the provider resource acted upon, and
 * DOES record failures and denials. Updates and deletes are blocked at the model
 * layer; there is no updated_at column.
 */
class InfraEvent extends Model
{
    use BelongsToWorkspace;

    protected $table = 'infra_events';

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'owner_type', 'owner_id', 'event', 'severity',
        'from_state', 'to_state', 'operation_id', 'actor_user_id', 'source',
        'provider', 'provider_resource_id', 'summary', 'context_json',
        'created_at',
    ];

    protected $casts = [
        'context_json' => 'array',
        'created_at'   => 'datetime',
    ];

    public const SEVERITY_INFO    = 'info';
    public const SEVERITY_SUCCESS = 'success';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR   = 'error';

    protected static function booted(): void
    {
        // Immutability enforced in code. A DB-level REVOKE UPDATE/DELETE is the
        // stronger control and is flagged as an open decision in 0.1-F §13.
        static::updating(fn () => throw new \RuntimeException('InfraEvent is append-only.'));
        static::deleting(fn () => throw new \RuntimeException('InfraEvent is append-only.'));
    }
}
