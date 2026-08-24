<?php

namespace App\Engines\Infrastructure\Email\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\Email\States\EmailForwarderState;
use App\Engines\Infrastructure\Email\Support\EmailAddress;
use App\Engines\Infrastructure\Email\Support\ForwarderLoopSafety;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * INFRA888 · E1 — a forwarder.
 *
 * Relays mail to an address outside the domain. The external destination is the
 * whole difference from an alias, and it is why loop safety exists: a loop
 * damages a third party who never agreed to anything, and the reputational cost
 * lands on the customer's domain.
 *
 * `loop_check_state` defaults to `unchecked`, which BLOCKS provisioning. A
 * forwarder that has never been evaluated is not assumed safe.
 */
class EmailForwarder extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $table = 'email_forwarders';

    protected $fillable = [
        'workspace_id', 'email_domain_id', 'source_local_part', 'destination_address',
        'lifecycle_state', 'previous_state', 'loop_check_state', 'loop_checked_at',
        'loop_check_reason', 'loop_check_hops', 'provider_connection_id',
        'last_operation_id', 'state_changed_at', 'state_changed_by_user_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'email_domain_id'          => 'integer',
        'loop_check_hops'          => 'integer',
        'provider_connection_id'   => 'integer',
        'last_operation_id'        => 'integer',
        'state_changed_by_user_id' => 'integer',
        'created_by_user_id'       => 'integer',
        'loop_checked_at'          => 'datetime',
        'state_changed_at'         => 'datetime',
    ];

    /**
     * See EmailDomain::$attributes for why database defaults are not enough.
     *
     * `loop_check_state` starting at `unchecked` is load-bearing: that value is
     * BLOCKING, so a forwarder that has never been evaluated cannot be
     * provisioned. Leaving it to the column default meant a freshly created
     * forwarder held NULL in memory, which is not a blocking value — the
     * safety default would have been bypassed by the very path that creates it.
     */
    protected $attributes = [
        'lifecycle_state'  => EmailForwarderState::REQUESTED,
        'loop_check_state' => ForwarderLoopSafety::UNCHECKED,
    ];

    protected $hidden = ['provider_connection_id', 'last_operation_id', 'active_flag'];

    public const OWNER_TYPE = 'email_forwarder';

    // ── relationships ────────────────────────────────────────────────────────

    public function domain(): BelongsTo
    {
        return $this->belongsTo(EmailDomain::class, 'email_domain_id');
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(InfraProviderConnection::class, 'provider_connection_id');
    }

    // ── loop safety ──────────────────────────────────────────────────────────

    /** True while this forwarder may not be provisioned. */
    public function isLoopBlocked(): bool
    {
        return ForwarderLoopSafety::isBlocking((string) $this->loop_check_state);
    }

    /**
     * Record the outcome of a loop evaluation.
     *
     * @param array{state:string,reason:string,hops:int} $verdict from ForwarderLoopSafety::evaluate()
     */
    public function recordLoopVerdict(array $verdict): self
    {
        $this->loop_check_state = $verdict['state'];
        $this->loop_check_reason = $verdict['reason'];
        $this->loop_check_hops = $verdict['hops'];
        $this->loop_checked_at = now();

        return $this;
    }

    // ── lifecycle ────────────────────────────────────────────────────────────

    /**
     * @throws InvalidArgumentException on an illegal transition
     */
    public function transitionTo(string $to): self
    {
        EmailForwarderState::assertTransition((string) $this->lifecycle_state, $to);

        $this->previous_state = $this->lifecycle_state;
        $this->lifecycle_state = $to;
        $this->state_changed_at = now();

        return $this;
    }

    public function isOperational(): bool
    {
        return in_array($this->lifecycle_state, EmailForwarderState::operational(), true);
    }

    public function isTerminal(): bool
    {
        return EmailForwarderState::isTerminal((string) $this->lifecycle_state);
    }

    public function sourceAddress(): ?string
    {
        $domain = $this->relationLoaded('domain') ? $this->getRelation('domain') : $this->domain;

        if ($domain === null) {
            return null;
        }

        return EmailAddress::compose((string) $this->source_local_part, (string) $domain->domain);
    }

    // ── projections ──────────────────────────────────────────────────────────

    /**
     * The loop verdict IS shown to the customer, with its reason. A blocked
     * forwarder that says only "failed" gives the customer nothing to act on,
     * and the reason strings in ForwarderLoopSafety are written to be read by
     * a customer rather than an operator.
     */
    public function toCustomerArray(): array
    {
        return [
            'id'                  => (int) $this->id,
            'source_local_part'   => (string) $this->source_local_part,
            'destination_address' => (string) $this->destination_address,
            'status'              => (string) $this->lifecycle_state,
            'loop_check'          => (string) $this->loop_check_state,
            'loop_check_reason'   => $this->loop_check_reason,
            'loop_checked_at'     => optional($this->loop_checked_at)->toIso8601String(),
            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }

    public function toAdminArray(): array
    {
        return $this->toCustomerArray() + [
            'workspace_id'           => (int) $this->workspace_id,
            'email_domain_id'        => (int) $this->email_domain_id,
            'previous_state'         => $this->previous_state,
            'loop_check_hops'        => $this->loop_check_hops,
            'provider_connection_id' => $this->provider_connection_id,
            'last_operation_id'      => $this->last_operation_id,
            'state_changed_at'       => optional($this->state_changed_at)->toIso8601String(),
            'state_changed_by'       => $this->state_changed_by_user_id,
        ];
    }
}
