<?php

namespace App\Engines\Infrastructure\Email\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;
use App\Engines\Infrastructure\Email\Support\EmailAddress;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use App\Engines\Infrastructure\Observation\Custody;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * INFRA888 · E1 — Business Email domain. The root entity.
 *
 * CUSTOMER PROJECTION IS A METHOD ON THIS CLASS, NOT A RESOURCE SOMEWHERE ELSE.
 * The white-label promise fails the first time a serialiser is written that
 * forgets to exclude the provider binding. Putting the projection here means
 * there is one place to audit and one place for the guard test to assert
 * against, and toCustomerArray() is enumerated rather than built by exclusion —
 * a new provider column added later cannot leak by default.
 */
class EmailDomain extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $table = 'email_domains';

    protected $fillable = [
        'workspace_id', 'domain', 'customer_domain_id', 'website_id', 'custody',
        'lifecycle_state', 'previous_state', 'verification_state', 'health_state',
        'provider_connection_id', 'dns_verified_at', 'last_observed_at',
        'last_observation_id', 'last_operation_id', 'state_changed_at',
        'state_changed_by_user_id', 'created_by_user_id', 'suspended_at',
        'suspension_reason', 'terminated_at', 'settings_json',
    ];

    protected $casts = [
        'customer_domain_id'       => 'integer',
        'website_id'               => 'integer',
        'provider_connection_id'   => 'integer',
        'last_observation_id'      => 'integer',
        'last_operation_id'        => 'integer',
        'state_changed_by_user_id' => 'integer',
        'created_by_user_id'       => 'integer',
        'dns_verified_at'          => 'datetime',
        'last_observed_at'         => 'datetime',
        'state_changed_at'         => 'datetime',
        'suspended_at'             => 'datetime',
        'terminated_at'            => 'datetime',
        'settings_json'            => 'array',
    ];

    /**
     * The model's own idea of where a new domain starts.
     *
     * WHY THIS IS NOT LEFT TO THE COLUMN DEFAULT
     * Eloquent does not read database defaults back after an insert, so a
     * freshly created model held an EMPTY lifecycle_state in memory while the
     * row on disk held 'connected'. The first transitionTo() then failed with
     * "unknown current state ''". A test caught it; nothing about the code
     * looked wrong.
     *
     * Declaring the initial state here makes the model authoritative and keeps
     * it in step with the state machine — a test asserts these values equal
     * each machine's initial().
     */
    protected $attributes = [
        'lifecycle_state'    => EmailDomainState::CONNECTED,
        'verification_state' => EmailVerificationState::UNVERIFIED,
        'health_state'       => 'unknown',
        'custody'            => Custody::UNKNOWN,
    ];

    /**
     * Never serialised by default. These identify the provider, and the
     * white-label rule is that no customer surface may see them.
     *
     * `active_flag` is a generated column that exists only to make the
     * live-row uniqueness constraint work; it is an implementation detail and
     * is not part of any projection.
     */
    protected $hidden = [
        'provider_connection_id', 'last_operation_id', 'last_observation_id', 'active_flag',
    ];

    /** The owner_type recorded on infra_operations and infra_provider_resources rows. */
    public const OWNER_TYPE = 'email_domain';

    /** The observation subject dimension family used in infra_observation_facts. */
    public const OBSERVATION_DIMENSIONS = ['mx', 'spf', 'dkim', 'dmarc'];

    // ── relationships ────────────────────────────────────────────────────────

    public function mailboxes(): HasMany
    {
        return $this->hasMany(EmailMailbox::class, 'email_domain_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(EmailAlias::class, 'email_domain_id');
    }

    public function forwarders(): HasMany
    {
        return $this->hasMany(EmailForwarder::class, 'email_domain_id');
    }

    public function catchAll(): HasOne
    {
        return $this->hasOne(EmailCatchAll::class, 'email_domain_id');
    }

    public function usageSamples(): HasMany
    {
        return $this->hasMany(EmailUsage::class, 'email_domain_id');
    }

    /**
     * The configured provider integration. Admin-only: nothing on a customer
     * surface may traverse this relationship.
     */
    public function providerConnection(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(InfraProviderConnection::class, 'provider_connection_id');
    }

    // ── lifecycle ────────────────────────────────────────────────────────────

    /**
     * @throws InvalidArgumentException on an illegal transition
     */
    public function transitionTo(string $to): self
    {
        EmailDomainState::assertTransition((string) $this->lifecycle_state, $to);

        $this->previous_state = $this->lifecycle_state;
        $this->lifecycle_state = $to;
        $this->state_changed_at = now();

        return $this;
    }

    /**
     * @throws InvalidArgumentException on an illegal transition
     */
    public function transitionVerificationTo(string $to): self
    {
        EmailVerificationState::assertTransition((string) $this->verification_state, $to);

        $this->verification_state = $to;

        return $this;
    }

    public function isOperational(): bool
    {
        return in_array($this->lifecycle_state, EmailDomainState::operational(), true);
    }

    public function isTerminal(): bool
    {
        return EmailDomainState::isTerminal((string) $this->lifecycle_state);
    }

    public function needsHumanDecision(): bool
    {
        return in_array($this->lifecycle_state, EmailDomainState::needsHuman(), true);
    }

    /** True once DNS has been observed, never merely because time has passed. */
    public function isDnsVerified(): bool
    {
        return $this->verification_state === EmailVerificationState::VERIFIED
            && $this->dns_verified_at !== null;
    }

    public function isBoundToProvider(): bool
    {
        return $this->provider_connection_id !== null;
    }

    public function normalizedDomain(): ?string
    {
        $host = strtolower(trim((string) $this->domain));

        return EmailAddress::isValidDomain($host) ? $host : null;
    }

    // ── projections ──────────────────────────────────────────────────────────

    /**
     * Everything a customer may ever be told about this domain.
     *
     * Enumerated deliberately. A column added to this table in a later
     * milestone does NOT appear here until someone adds it on purpose, which is
     * the only construction that survives future changes.
     */
    public function toCustomerArray(): array
    {
        return [
            'id'                 => (int) $this->id,
            'domain'             => (string) $this->domain,
            'status'             => (string) $this->lifecycle_state,
            'verification'       => (string) $this->verification_state,
            'health'             => (string) $this->health_state,
            'dns_verified_at'    => optional($this->dns_verified_at)->toIso8601String(),
            'last_checked_at'    => optional($this->last_observed_at)->toIso8601String(),
            'suspended_at'       => optional($this->suspended_at)->toIso8601String(),
            'created_at'         => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * The admin view. Admins see provider identity — that is the whole point of
     * the two-plane split — but still never see a secret.
     */
    public function toAdminArray(): array
    {
        return $this->toCustomerArray() + [
            'workspace_id'           => (int) $this->workspace_id,
            'custody'                => (string) $this->custody,
            'previous_state'         => $this->previous_state,
            'provider_connection_id' => $this->provider_connection_id,
            'last_operation_id'      => $this->last_operation_id,
            'last_observation_id'    => $this->last_observation_id,
            'state_changed_at'       => optional($this->state_changed_at)->toIso8601String(),
            'state_changed_by'       => $this->state_changed_by_user_id,
            'suspension_reason'      => $this->suspension_reason,
            'terminated_at'          => optional($this->terminated_at)->toIso8601String(),
        ];
    }
}
