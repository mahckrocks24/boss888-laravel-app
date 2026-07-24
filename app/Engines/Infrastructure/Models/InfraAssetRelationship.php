<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed edge in the canonical infrastructure graph (Phase 3B).
 *
 * The graph is what makes blast-radius answerable: a domain attached_to a
 * website, a website hosted_on a server, a server provided_by a provider, a
 * monitor monitors an asset. Traversing edges answers "which customers are
 * affected?" when any node degrades.
 */
class InfraAssetRelationship extends Model
{
    use BelongsToWorkspace;

    protected $table = 'infra_asset_relationships';

    protected $fillable = [
        'workspace_id', 'from_asset_id', 'to_asset_id', 'relationship_type', 'metadata_json',
    ];

    protected $casts = ['metadata_json' => 'array'];

    public const REL_ATTACHED_TO  = 'attached_to';
    public const REL_HOSTED_ON    = 'hosted_on';
    public const REL_PROVIDED_BY  = 'provided_by';
    public const REL_MONITORS     = 'monitors';
    public const REL_SECURED_BY   = 'secured_by';
    public const REL_DEPLOYS_TO   = 'deploys_to';
    public const REL_SERVES       = 'serves';
    public const REL_DEPENDS_ON   = 'depends_on';

    public function fromAsset(): BelongsTo
    {
        return $this->belongsTo(InfraAsset::class, 'from_asset_id');
    }

    public function toAsset(): BelongsTo
    {
        return $this->belongsTo(InfraAsset::class, 'to_asset_id');
    }
}
