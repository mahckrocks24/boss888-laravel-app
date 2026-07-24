<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\States\SubscriptionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The customer's infrastructure entitlement. Workspace-owned.
 *
 * State changes go through transitionTo(), never a bare update(), so an illegal
 * lifecycle jump throws instead of silently corrupting commercial state.
 */
class InfraSubscription extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $table = 'infra_subscriptions';

    protected $fillable = [
        'workspace_id', 'plan_id', 'product_id', 'state', 'previous_state',
        'quantity', 'currency', 'unit_amount_minor', 'billing_period',
        'setup_fee_minor', 'term_start_at', 'term_end_at',
        'current_period_start', 'current_period_end', 'next_renewal_at',
        'auto_renew', 'grace_period_ends_at', 'suspended_at', 'cancelled_at',
        'cancellation_reason', 'billing_reference', 'billing_mode',
        'created_by', 'metadata_json',
        // Phase 2A-1 commercial lifecycle
        'trial_ends_at', 'plan_version_at_purchase', 'price_locked_until',
        'renewal_amount_minor', 'superseded_by_subscription_id',
        'archived_at', 'support_tier',
    ];

    protected $casts = [
        'quantity'             => 'integer',
        'unit_amount_minor'    => 'integer',
        'setup_fee_minor'      => 'integer',
        'auto_renew'           => 'boolean',
        'term_start_at'        => 'datetime',
        'term_end_at'          => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
        'next_renewal_at'      => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'suspended_at'         => 'datetime',
        'cancelled_at'         => 'datetime',
        'metadata_json'        => 'array',
        'trial_ends_at'            => 'datetime',
        'price_locked_until'       => 'datetime',
        'archived_at'              => 'datetime',
        'plan_version_at_purchase' => 'integer',
        'renewal_amount_minor'     => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InfraPlan::class, 'plan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InfraProduct::class, 'product_id');
    }

    public function hostingAccounts(): HasMany
    {
        return $this->hasMany(InfraHostingAccount::class, 'subscription_id');
    }

    /** Total recurring value, quantity-aware. The platform cannot express this. */
    public function totalAmountMinor(): int
    {
        return $this->unit_amount_minor * max(1, (int) $this->quantity);
    }

    public function isEntitled(): bool
    {
        return in_array($this->state, SubscriptionState::entitled(), true);
    }

    /**
     * @throws \InvalidArgumentException on an illegal transition.
     */
    public function transitionTo(string $to): self
    {
        SubscriptionState::assertTransition((string) $this->state, $to);

        $this->previous_state = $this->state;
        $this->state = $to;

        return $this;
    }
}
