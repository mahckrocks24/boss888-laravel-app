<?php

namespace App\Engines\Infrastructure\Email\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use App\Engines\Infrastructure\Observation\AssetLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INFRA888 · E1 — one usage measurement.
 *
 * Append-only in intent: a sample records what was true at `observed_at` and is
 * never edited afterwards. Corrections are new samples, so the history of what
 * we believed and when stays intact.
 *
 * Every measure is nullable, and that is the point. A provider that does not
 * report send counts produces NULL, never 0. `messages_sent => null` renders as
 * "not reported"; `messages_sent => 0` is a claim that no mail was sent. The
 * second is a lie the customer has no way to detect.
 */
class EmailUsage extends Model
{
    use BelongsToWorkspace;

    protected $table = 'email_usage';

    protected $fillable = [
        'workspace_id', 'email_domain_id', 'scope', 'email_mailbox_id',
        'storage_used_mb', 'storage_quota_mb', 'messages_sent', 'messages_received',
        'mailbox_count', 'source', 'confidence', 'observed_at',
        'provider_connection_id', 'operation_id', 'idempotency_key',
    ];

    protected $casts = [
        'email_domain_id'        => 'integer',
        'email_mailbox_id'       => 'integer',
        'storage_used_mb'        => 'integer',
        'storage_quota_mb'       => 'integer',
        'messages_sent'          => 'integer',
        'messages_received'      => 'integer',
        'mailbox_count'          => 'integer',
        'provider_connection_id' => 'integer',
        'operation_id'           => 'integer',
        'observed_at'            => 'datetime',
    ];

    /** See EmailDomain::$attributes for why database defaults are not enough. */
    protected $attributes = [
        'source'     => self::SOURCE_PROVIDER,
        'confidence' => AssetLifecycle::C_UNKNOWN,
    ];

    protected $hidden = ['provider_connection_id', 'operation_id', 'idempotency_key'];

    public const SCOPE_DOMAIN = 'domain';
    public const SCOPE_MAILBOX = 'mailbox';

    public static function scopes(): array
    {
        return [self::SCOPE_DOMAIN, self::SCOPE_MAILBOX];
    }

    /**
     * Where the number came from. Same vocabulary as infra_usage_records, so a
     * rollup into billing does not have to translate between two ideas of
     * provenance.
     */
    public const SOURCE_PROVIDER = 'provider';
    public const SOURCE_INTERNAL = 'internal';
    public const SOURCE_ESTIMATED = 'estimated';

    public static function sources(): array
    {
        return [self::SOURCE_PROVIDER, self::SOURCE_INTERNAL, self::SOURCE_ESTIMATED];
    }

    /** The estate-wide confidence ladder, reused rather than reinvented. */
    public static function confidenceLevels(): array
    {
        return AssetLifecycle::confidenceLadder();
    }

    // ── relationships ────────────────────────────────────────────────────────

    public function domain(): BelongsTo
    {
        return $this->belongsTo(EmailDomain::class, 'email_domain_id');
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(EmailMailbox::class, 'email_mailbox_id');
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(InfraProviderConnection::class, 'provider_connection_id');
    }

    // ── derived ──────────────────────────────────────────────────────────────

    /**
     * Percentage of quota consumed by this sample, or null when either side of
     * the ratio was not reported. Never substitutes a zero for a missing value.
     */
    public function quotaUsedPercent(): ?float
    {
        if ($this->storage_used_mb === null || $this->storage_quota_mb === null) {
            return null;
        }

        if ((int) $this->storage_quota_mb <= 0) {
            return null;
        }

        return round(((int) $this->storage_used_mb / (int) $this->storage_quota_mb) * 100, 2);
    }

    /** True when this sample is trustworthy enough to drive a billing decision. */
    public function isBillingGrade(): bool
    {
        return $this->source === self::SOURCE_PROVIDER
            && AssetLifecycle::atLeast((string) $this->confidence, AssetLifecycle::C_OBSERVED);
    }

    // ── projections ──────────────────────────────────────────────────────────

    public function toCustomerArray(): array
    {
        return [
            'scope'              => (string) $this->scope,
            'email_mailbox_id'   => $this->email_mailbox_id,
            'storage_used_mb'    => $this->storage_used_mb,
            'storage_quota_mb'   => $this->storage_quota_mb,
            'messages_sent'      => $this->messages_sent,
            'messages_received'  => $this->messages_received,
            'mailbox_count'      => $this->mailbox_count,
            'quota_used_percent' => $this->quotaUsedPercent(),
            'observed_at'        => optional($this->observed_at)->toIso8601String(),
        ];
    }

    public function toAdminArray(): array
    {
        return $this->toCustomerArray() + [
            'id'                     => (int) $this->id,
            'workspace_id'           => (int) $this->workspace_id,
            'email_domain_id'        => (int) $this->email_domain_id,
            'source'                 => (string) $this->source,
            'confidence'             => (string) $this->confidence,
            'billing_grade'          => $this->isBillingGrade(),
            'provider_connection_id' => $this->provider_connection_id,
            'operation_id'           => $this->operation_id,
        ];
    }
}
