<?php

namespace App\Engines\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PLANNED movement of subscribers between plan versions.
 *
 * Not workspace-owned: a migration batch normally spans tenants, so it is a
 * platform-level operation. Per-subscription workspace scoping is resolved at
 * execution time — which Phase 2A-2 deliberately does not perform.
 *
 * The impact snapshot is taken at planning time so an approver sees the blast
 * radius that was actually assessed, rather than a number that drifted between
 * planning and approval.
 */
class InfraSubscriptionMigration extends Model
{
    protected $table = 'infra_subscription_migrations';

    public const STATE_PLANNED   = 'planned';
    public const STATE_APPROVED  = 'approved';
    public const STATE_REJECTED  = 'rejected';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_EXECUTING = 'executing';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED    = 'failed';

    public const STRATEGY_IMMEDIATE   = 'immediate';
    public const STRATEGY_AT_RENEWAL  = 'at_renewal';
    public const STRATEGY_AT_TERM_END = 'at_term_end';

    protected $fillable = [
        'workspace_id', 'from_plan_id', 'to_plan_id', 'state', 'previous_state',
        'strategy', 'affected_subscription_count', 'impact_json',
        'price_increases', 'allowances_decrease', 'reason',
        'planned_by', 'approved_by', 'approved_at', 'approval_id',
        'executed_count', 'executed_at', 'failure_summary',
    ];

    protected $casts = [
        'impact_json'                 => 'array',
        'price_increases'             => 'boolean',
        'allowances_decrease'         => 'boolean',
        'affected_subscription_count' => 'integer',
        'executed_count'              => 'integer',
        'approved_at'                 => 'datetime',
        'executed_at'                 => 'datetime',
    ];

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(InfraPlan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(InfraPlan::class, 'to_plan_id');
    }

    /** Migrations that worsen a customer's position need a human to say so explicitly. */
    public function isAdverse(): bool
    {
        return $this->price_increases || $this->allowances_decrease;
    }

    public static function strategies(): array
    {
        return [self::STRATEGY_IMMEDIATE, self::STRATEGY_AT_RENEWAL, self::STRATEGY_AT_TERM_END];
    }
}
