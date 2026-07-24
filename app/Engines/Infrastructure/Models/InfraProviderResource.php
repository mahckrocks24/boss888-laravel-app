<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The mapping between one INFRA888 business record and one external provider object.
 *
 * This is the fix for the Phase 0 defect where CustomDomainService::connect():66
 * captured the provider resource ID and discarded it, forcing disconnect() to
 * re-query the provider by hostname.
 *
 * provider_resource_id is OPAQUE. Never parse it, never infer meaning from its
 * shape, never build business logic on its format.
 */
class InfraProviderResource extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $table = 'infra_provider_resources';

    protected $fillable = [
        'workspace_id', 'provider_connection_id', 'owner_type', 'owner_id',
        'provider', 'provider_resource_type', 'provider_resource_id',
        'normalized_state', 'provider_state', 'last_synced_at',
        'last_operation_id', 'last_error_code', 'last_error_summary',
        'idempotency_reference', 'provider_metadata_json',
    ];

    protected $casts = [
        'last_synced_at'         => 'datetime',
        'provider_metadata_json' => 'array',
    ];

    /** Stale = we have not confirmed provider reality recently enough to trust it. */
    public function isStale(int $maxAgeMinutes = 60): bool
    {
        if (!$this->last_synced_at) {
            return true;
        }

        return $this->last_synced_at->lt(now()->subMinutes($maxAgeMinutes));
    }
}
