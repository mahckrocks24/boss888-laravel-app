<?php

namespace App\Engines\Infrastructure\Email\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\Email\States\EmailCatchAllState;
use App\Engines\Infrastructure\Email\Support\EmailAddress;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use RuntimeException;

/**
 * INFRA888 · E1 — the per-domain catch-all.
 *
 * One row per domain, enforced by a unique constraint. No soft deletes: a
 * catch-all is switched off rather than removed, and the row dies with its
 * domain.
 */
class EmailCatchAll extends Model
{
    use BelongsToWorkspace;

    protected $table = 'email_catchall';

    protected $fillable = [
        'workspace_id', 'email_domain_id', 'state', 'previous_state', 'target_type',
        'target_mailbox_id', 'target_address', 'provider_connection_id',
        'last_operation_id', 'state_changed_at', 'state_changed_by_user_id',
        'created_by_user_id',
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
        'state' => EmailCatchAllState::DISABLED,
    ];

    protected $hidden = ['provider_connection_id', 'last_operation_id'];

    public const OWNER_TYPE = 'email_catchall';

    public const TARGET_MAILBOX = 'mailbox';
    public const TARGET_ADDRESS = 'address';

    public static function targetTypes(): array
    {
        return [self::TARGET_MAILBOX, self::TARGET_ADDRESS];
    }

    protected static function booted(): void
    {
        static::saving(function (self $catchAll) {
            $catchAll->assertTargetMatchesState();
        });
    }

    /**
     * A catch-all that is delivering must know where. The schema enforces this
     * too; this exists so the failure is legible rather than an opaque
     * CHECK-constraint violation.
     *
     * @throws RuntimeException when state and target disagree
     */
    public function assertTargetMatchesState(): void
    {
        $state = (string) $this->state;
        $hasTarget = $this->target_mailbox_id !== null || $this->target_address !== null;
        $needsTarget = in_array($state, EmailCatchAllState::requiresTarget(), true);

        if ($needsTarget && ! $hasTarget) {
            throw new RuntimeException(
                "EmailCatchAll: state '{$state}' delivers mail and therefore requires a target."
            );
        }

        if (! $hasTarget) {
            // No target and none required. target_type must be absent too, or
            // the row claims an intent it cannot act on.
            if ($this->target_type !== null) {
                throw new RuntimeException(
                    "EmailCatchAll: state '{$state}' has no target, so target_type must be null."
                );
            }

            return;
        }

        $type = (string) $this->target_type;

        if (! in_array($type, self::targetTypes(), true)) {
            throw new RuntimeException(
                'EmailCatchAll: target_type must be one of ' . implode('|', self::targetTypes()) . ", got '{$type}'."
            );
        }

        if ($type === self::TARGET_MAILBOX && ($this->target_mailbox_id === null || $this->target_address !== null)) {
            throw new RuntimeException(
                'EmailCatchAll: a mailbox target requires target_mailbox_id and must not set target_address.'
            );
        }

        if ($type === self::TARGET_ADDRESS && ($this->target_address === null || $this->target_mailbox_id !== null)) {
            throw new RuntimeException(
                'EmailCatchAll: an address target requires target_address and must not set target_mailbox_id.'
            );
        }

        if ($type === self::TARGET_ADDRESS && ! EmailAddress::isValid((string) $this->target_address)) {
            throw new RuntimeException('EmailCatchAll: target_address is not a usable email address.');
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
        EmailCatchAllState::assertTransition((string) $this->state, $to);

        $this->previous_state = $this->state;
        $this->state = $to;
        $this->state_changed_at = now();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->state === EmailCatchAllState::ENABLED;
    }

    // ── projections ──────────────────────────────────────────────────────────

    public function toCustomerArray(): array
    {
        return [
            'id'                => (int) $this->id,
            'status'            => (string) $this->state,
            'enabled'           => $this->isEnabled(),
            'target_type'       => $this->target_type,
            'target_mailbox_id' => $this->target_mailbox_id,
            'target_address'    => $this->target_address,
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
