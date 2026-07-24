<?php

namespace App\Engines\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a provider charged US for a resource.
 *
 * Not using BelongsToWorkspace: workspace_id is nullable because platform-level
 * costs (a flat edge fee, a base plan charge) are not attributable to one tenant.
 * Workspace-scoped reads are done explicitly in the service layer.
 *
 * amount_minor is SIGNED — credits and refunds are negative entries rather than a
 * separate table, so summing a period always yields true net cost.
 *
 * This is the cost half of gross margin. Revenue lives on infra_subscriptions.
 * Directive §8: infrastructure carries zero AI credits but must still produce
 * cost events.
 */
class InfraCostEntry extends Model
{
    protected $table = 'infra_cost_entries';

    protected $fillable = [
        'workspace_id', 'subscription_id', 'provider_resource_id', 'provider',
        'cost_type', 'currency', 'amount_minor', 'incurred_on',
        'provider_reference', 'source', 'metadata_json',
        // Phase 2A-1 cost period + reconciliation. Caught by
        // MassAssignmentDriftTest before shipping — the 4th instance of this
        // defect class in this project.
        'period_start', 'period_end', 'reconciled_at', 'reconciled_amount_minor',
    ];

    protected $casts = [
        'amount_minor'  => 'integer',
        'incurred_on'   => 'date',
        'period_start'  => 'date',
        'period_end'    => 'date',
        'reconciled_at' => 'datetime',
        'reconciled_amount_minor' => 'integer',
        'metadata_json' => 'array',
    ];

    public const SOURCE_ESTIMATED = 'estimated';
    public const SOURCE_INVOICE   = 'invoice';
    public const SOURCE_API       = 'api';
}
