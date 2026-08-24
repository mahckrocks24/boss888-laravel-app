<?php

namespace App\Engines\Infrastructure\Email\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\Email\States\EmailAliasState;
use App\Engines\Infrastructure\Email\Support\EmailAddress;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use RuntimeException;

/**
 * INFRA888 · E1 — an alias.
 *
 * Delivers mail for one local part into a mailbox we operate, or to one named
 * address. The exclusivity of those two targets is enforced by a CHECK
 * constraint in the schema; the guard here exists in addition to it so the
 * violation is caught with a useful message before it reaches the database.
 */
class EmailAlias extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $table = 'email_aliases';

    protected $fillable = [
        'workspace_id', 'email_domain_id', 'source_local_part', 'target_type',
        'target_mailbox_id', 'target_address', 'lifecycle_state', 'previous_state',
        'provider_connection_id', 'last_operation_id', 'state_changed_at',
        'state_changed_by_user_id', 'created_by_user_id',
    ];

    protected $casts = [
        'email_domain_id'          => 'integer',
        'target_mailbox_id'        => 'integer',
        'provider_connection_id'   => 'integer',
        'last_operation_id'        => 'integer',
        'state_changed_by_user_id' => 'integer',
        'created_by_user_id'       => 'integer',
        'state_changed_at'         => 'datetime',
    ];

    /** See EmailDomain::$attributes for why database defaults are not enough. */
    protected $attributes = [
        'lifecycle_state' => EmailAliasState::REQUESTED,
    ];

    protected $hidden = ['provider_connection_id', 'last_operation_id', 'active_flag'];

    public const OWNER_TYPE = 'email_alias';

    public const TARGET_MAILBOX = 'mailbox';
    public const TARGET_ADDRESS = 'address';

    public static function targetTypes(): array
    {
        return [self::TARGET_MAILBOX, self::TARGET_ADDRESS];
    }

    protected static function booted(): void
    {
        // The schema makes the invalid shapes unrepresentable; this makes them
        // legible. A CHECK-constraint violation surfaces as an SQL error with no
        // indication of which rule was broken.
        static::saving(function (self $alias) {
            $alias->assertTargetIsExclusive();
        });
    }

    /**
     * @throws RuntimeException when the declared target type and the populated
     *                          target columns disagree
     */
    public function assertTargetIsExclusive(): void
    {
        $type = (string) $this->target_type;

        if (! in_array($type, self::targetTypes(), true)) {
            throw new RuntimeException(
                "EmailAlias: target_type must be one of " . implode('|', self::targetTypes()) . ", got '{$type}'."
            );
        }

        if ($type === self::TARGET_MAILBOX && ($this->target_mailbox_id === null || $this->target_address !== null)) {
            throw new RuntimeException(
                'EmailAlias: a mailbox alias requires target_mailbox_id and must not set target_address.'
            );
        }

        if ($type === self::TARGET_ADDRESS && ($this->target_address === null || $this->target_mailbox_id !== null)) {
            throw new RuntimeException(
                'EmailAlias: an address alias requires target_address and must not set target_mailbox_id.'
            );
        }

        if ($type === self::TARGET_ADDRESS && ! EmailAddress::isValid((string) $this->target_address)) {
            throw new RuntimeException(
                'EmailAlias: target_address is not a usable email address.'
            );
        }
    }

    // ── relationships ────────────────────────────────────────────────────────

    public function domain(): BelongsTo
    {
        return $this->belongsTo(EmailDomain::class, 'email_domain_id');
    }

    public function targetMailbox(): BelongsTo
    {
        return $this->belongsTo(EmailMailbox::class, 'target_mailbox_id');
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(InfraProviderConnection::class, 'provider_connection_id');
    }

    // ── lifecycle ────────────────────────────────────────────────────────────

    /**
     * @throws InvalidArgumentException on an illegal transition
     */
    public function transitionTo(string $to): self
    {
        EmailAliasState::assertTransition((string) $this->lifecycle_state, $to);

        $this->previous_state = $this->lifecycle_state;
        $this->lifecycle_state = $to;
        $this->state_changed_at = now();

        return $this;
    }

    public function isOperational(): bool
    {
        return in_array($this->lifecycle_state, EmailAliasState::operational(), true);
    }

    public function isTerminal(): bool
    {
        return EmailAliasState::isTerminal((string) $this->lifecycle_state);
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

    public function toCustomerArray(): array
    {
        return [
            'id'                => (int) $this->id,
            'source_local_part' => (string) $this->source_local_part,
            'target_type'       => (string) $this->target_type,
            'target_mailbox_id' => $this->target_mailbox_id,
            'target_address'    => $this->target_address,
            'status'            => (string) $this->lifecycle_state,
            'created_at'        => optional($this->created_at)->toIso8601String(),
        ];
    }

    public function toAdminArray(): array
    {
        return $this->toCustomerArray() + [
            'workspace_id'           => (int) $this->workspace_id,
            'email_domain_id'        => (int) $this->email_domain_id,
            'previous_state'         => $this->previous_state,
            'provider_connection_id' => $this->provider_connection_id,
            'last_operation_id'      => $this->last_operation_id,
            'state_changed_at'       => optional($this->state_changed_at)->toIso8601String(),
            'state_changed_by'       => $this->state_changed_by_user_id,
        ];
    }
}
