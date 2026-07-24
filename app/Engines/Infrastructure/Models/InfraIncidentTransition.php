<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Append-only incident lifecycle history (Phase 3B).
 *
 * Every incident transition is a permanent, actor-attributed, workspace-isolated
 * record. The incident timeline cannot be rewritten — an operational history that
 * can be edited is not evidence.
 */
class InfraIncidentTransition extends Model
{
    use BelongsToWorkspace;

    protected $table = 'infra_incident_transitions';

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'incident_id', 'from_state', 'to_state',
        'actor_user_id', 'actor_type', 'note', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('InfraIncidentTransition is append-only.'));
        static::deleting(fn () => throw new RuntimeException('InfraIncidentTransition is append-only.'));
    }
}
