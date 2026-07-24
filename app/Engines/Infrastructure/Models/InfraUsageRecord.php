<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Measured consumption for one subscription, one metric, one period.
 *
 * Overage is EVALUATED at metering time against the pinned plan and stored, rather
 * than recomputed later — so a future plan change cannot retroactively alter a
 * customer's historical overage bill.
 */
class InfraUsageRecord extends Model
{
    use BelongsToWorkspace;

    protected $table = 'infra_usage_records';

    protected $fillable = [
        'workspace_id', 'subscription_id', 'owner_type', 'owner_id', 'metric',
        'quantity', 'unit', 'period_start', 'period_end', 'measured_at',
        'source', 'included_quantity', 'overage_quantity', 'overage_amount_minor',
        'currency', 'is_billable', 'is_invoiced', 'metadata_json',
        // Phase 2A-1 usage behaviour. Omitting these from $fillable silently
        // DROPS them on save — the same defect that hid the approval requester.
        'behavior', 'warning_threshold_pct', 'warning_sent',
    ];

    protected $casts = [
        'quantity'             => 'integer',
        'included_quantity'    => 'integer',
        'overage_quantity'     => 'integer',
        'overage_amount_minor' => 'integer',
        'is_billable'          => 'boolean',
        'warning_sent'         => 'boolean',
        'warning_threshold_pct'=> 'integer',
        'is_invoiced'          => 'boolean',
        'period_start'         => 'date',
        'period_end'           => 'date',
        'measured_at'          => 'datetime',
        'metadata_json'        => 'array',
    ];

    public const METRIC_STORAGE_MB   = 'storage_mb';
    public const METRIC_BANDWIDTH_MB = 'bandwidth_mb';
    public const METRIC_MAILBOXES    = 'mailbox_count';
    public const METRIC_SITES        = 'site_count';
}
