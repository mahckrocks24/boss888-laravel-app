<?php

namespace App\Engines\Infrastructure\Email\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\Support\EmailAddress;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * INFRA888 · E1 — a mailbox.
 *
 * The billable unit, and the only entity here whose deletion destroys customer
 * data. Nothing in this class stores, accepts, returns or logs a password.
 */
class EmailMailbox extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $table = 'email_mailboxes';

    protected $fillable = [
        'workspace_id', 'email_domain_id', 'local_part', 'display_name', 'quota_mb',
        'lifecycle_state', 'previous_state', 'suspension_reason_code', 'suspended_at',
        'suspended_by_user_id', 'suspension_note', 'password_set_at',
        'last_password_reset_at', 'storage_used_mb', 'usage_observed_at',
        'last_activity_at', 'provider_connection_id', 'last_operation_id',
        'state_changed_at', 'state_changed_by_user_id', 'created_by_user_id',
        'settings_json',
    ];

    protected $casts = [
        'email_domain_id'          => 'integer',
        'quota_mb'                 => 'integer',
        'storage_used_mb'          => 'integer',
        'provider_connection_id'   => 'integer',
        'last_operation_id'        => 'integer',
        'suspended_by_user_id'     => 'integer',
        'state_changed_by_user_id' => 'integer',
        'created_by_user_id'       => 'integer',
        'suspended_at'             => 'datetime',
        'password_set_at'          => 'datetime',
        'last_password_reset_at'   => 'datetime',
        'usage_observed_at'        => 'datetime',
        'last_activity_at'         => 'datetime',
        'state_changed_at'         => 'datetime',
        'settings_json'            => 'array',
    ];

    /** See EmailDomain::$attributes for why database defaults are not enough. */
    protected $attributes = [
        'lifecycle_state'        => EmailMailboxState::REQUESTED,
        'suspension_reason_code' => self::SUSPENSION_NONE,
    ];

    protected $hidden = ['provider_connection_id', 'last_operation_id', 'active_flag'];

    public const OWNER_TYPE = 'email_mailbox';

    // ── suspension reasons ───────────────────────────────────────────────────
    // The state says mail is stopped. These say who may restart it.

    /** Not suspended. */
    public const SUSPENSION_NONE = 'none';
    /** The customer asked. The customer may undo it. */
    public const SUSPENSION_CUSTOMER_REQUESTED = 'customer_requested';
    /** An operator acted. Only an operator may undo it. */
    public const SUSPENSION_ADMIN_ENFORCED = 'admin_enforced';
    /** Unpaid. Clears when billing clears, not on request. */
    public const SUSPENSION_BILLING_HOLD = 'billing_hold';
    /** Abuse or compromise. Requires review before restoration. */
    public const SUSPENSION_ABUSE_HOLD = 'abuse_hold';

    public static function suspensionReasons(): array
    {
        return [
            self::SUSPENSION_NONE,
            self::SUSPENSION_CUSTOMER_REQUESTED,
            self::SUSPENSION_ADMIN_ENFORCED,
            self::SUSPENSION_BILLING_HOLD,
            self::SUSPENSION_ABUSE_HOLD,
        ];
    }

    /** Reasons a customer may lift without an operator. */
    public static function customerReversibleReasons(): array
    {
        return [self::SUSPENSION_CUSTOMER_REQUESTED];
    }

    // ── relationships ────────────────────────────────────────────────────────

    public function domain(): BelongsTo
    {
        return $this->belongsTo(EmailDomain::class, 'email_domain_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(EmailAlias::class, 'target_mailbox_id');
    }

    public function usageSamples(): HasMany
    {
        return $this->hasMany(EmailUsage::class, 'email_mailbox_id');
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(InfraProviderConnection::class, 'provider_connection_id');
    }

    // ── derived identity ─────────────────────────────────────────────────────

    /**
     * The addressable form. Derived rather than stored so there is exactly one
     * source of truth for (domain, local_part).
     */
    public function address(): ?string
    {
        $domain = $this->relationLoaded('domain') ? $this->getRelation('domain') : $this->domain;

        if ($domain === null) {
            return null;
        }

        return EmailAddress::compose((string) $this->local_part, (string) $domain->domain);
    }

    // ── lifecycle ────────────────────────────────────────────────────────────

    /**
     * @throws InvalidArgumentException on an illegal transition
     */
    public function transitionTo(string $to): self
    {
        EmailMailboxState::assertTransition((string) $this->lifecycle_state, $to);

        $this->previous_state = $this->lifecycle_state;
        $this->lifecycle_state = $to;
        $this->state_changed_at = now();

        return $this;
    }

    public function isOperational(): bool
    {
        return in_array($this->lifecycle_state, EmailMailboxState::operational(), true);
    }

    public function isBillable(): bool
    {
        return in_array($this->lifecycle_state, EmailMailboxState::billable(), true);
    }

    public function isTerminal(): bool
    {
        return EmailMailboxState::isTerminal((string) $this->lifecycle_state);
    }

    public function needsHumanDecision(): bool
    {
        return in_array($this->lifecycle_state, EmailMailboxState::needsHuman(), true);
    }

    public function isSuspended(): bool
    {
        return $this->lifecycle_state === EmailMailboxState::SUSPENDED;
    }

    public function isCustomerRestorable(): bool
    {
        return $this->isSuspended()
            && in_array($this->suspension_reason_code, self::customerReversibleReasons(), true);
    }

    /**
     * Percentage of quota consumed, or null when never sampled.
     *
     * Returns null rather than 0 for an unsampled mailbox on purpose: a list
     * showing "0% used" for something never measured is a fabricated number.
     */
    public function quotaUsedPercent(): ?float
    {
        if ($this->storage_used_mb === null || (int) $this->quota_mb <= 0) {
            return null;
        }

        return round(((int) $this->storage_used_mb / (int) $this->quota_mb) * 100, 2);
    }

    // ── projections ──────────────────────────────────────────────────────────

    public function toCustomerArray(): array
    {
        return [
            'id'                  => (int) $this->id,
            'local_part'          => (string) $this->local_part,
            'display_name'        => $this->display_name,
            'status'              => (string) $this->lifecycle_state,
            'suspended'           => $this->isSuspended(),
            'can_restore_myself'  => $this->isCustomerRestorable(),
            'quota_mb'            => (int) $this->quota_mb,
            'storage_used_mb'     => $this->storage_used_mb,
            'quota_used_percent'  => $this->quotaUsedPercent(),
            'usage_observed_at'   => optional($this->usage_observed_at)->toIso8601String(),
            'last_activity_at'    => optional($this->last_activity_at)->toIso8601String(),
            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }

    public function toAdminArray(): array
    {
        return $this->toCustomerArray() + [
            'workspace_id'            => (int) $this->workspace_id,
            'email_domain_id'         => (int) $this->email_domain_id,
            'previous_state'          => $this->previous_state,
            'suspension_reason_code'  => (string) $this->suspension_reason_code,
            'suspension_note'         => $this->suspension_note,
            'suspended_by'            => $this->suspended_by_user_id,
            'provider_connection_id'  => $this->provider_connection_id,
            'last_operation_id'       => $this->last_operation_id,
            'password_set_at'         => optional($this->password_set_at)->toIso8601String(),
            'last_password_reset_at'  => optional($this->last_password_reset_at)->toIso8601String(),
            'state_changed_at'        => optional($this->state_changed_at)->toIso8601String(),
            'state_changed_by'        => $this->state_changed_by_user_id,
        ];
    }
}
