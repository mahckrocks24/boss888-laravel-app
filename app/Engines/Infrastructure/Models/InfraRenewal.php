<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\States\RenewalState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A renewal event with its own lifecycle and retry history.
 *
 * Workspace-owned. No payment integration in this phase — `attempting` under
 * Mode A means a staff member is processing it.
 */
class InfraRenewal extends Model
{
    use BelongsToWorkspace;

    protected $table = 'infra_renewals';

    protected $fillable = [
        'workspace_id', 'subscription_id', 'state', 'previous_state', 'renewal_mode',
        'scheduled_for', 'reminder_sent_at', 'attempted_at', 'completed_at',
        'attempt_count', 'billing_period', 'currency', 'amount_minor',
        'new_period_start', 'new_period_end', 'failure_code', 'failure_summary',
        'actor_user_id', 'metadata_json',
    ];

    protected $casts = [
        'scheduled_for'    => 'datetime',
        'reminder_sent_at' => 'datetime',
        'attempted_at'     => 'datetime',
        'completed_at'     => 'datetime',
        'new_period_start' => 'datetime',
        'new_period_end'   => 'datetime',
        'attempt_count'    => 'integer',
        'amount_minor'     => 'integer',
        'metadata_json'    => 'array',
    ];

    public const MODE_MANUAL    = 'manual';
    public const MODE_AUTOMATED = 'automated';

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(InfraSubscription::class, 'subscription_id');
    }

    public function isActionable(): bool
    {
        return in_array($this->state, RenewalState::actionable(), true);
    }

    /**
     * @throws \InvalidArgumentException on an illegal transition.
     */
    public function transitionTo(string $to): self
    {
        RenewalState::assertTransition((string) $this->state, $to);

        $this->previous_state = $this->state;
        $this->state = $to;

        return $this;
    }
}
