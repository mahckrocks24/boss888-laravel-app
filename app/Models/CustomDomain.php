<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom domain a customer is connecting to one of their websites via the
 * Cloudflare-for-SaaS custom-hostname flow. Tenant-scoped (BelongsToWorkspace):
 * every query is bound to the active WorkspaceContext, so one workspace can
 * never read another's domains.
 */
class CustomDomain extends Model
{
    use BelongsToWorkspace;

    protected $table = 'custom_domains';

    protected $guarded = ['id'];

    protected $casts = [
        'is_apex'              => 'boolean',
        'verification_records' => 'array',
        'metadata'             => 'array',
        'last_checked_at'      => 'datetime',
        'connected_at'         => 'datetime',
        'verified_at'          => 'datetime',
        'ssl_issued_at'        => 'datetime',
        'disconnected_at'      => 'datetime',
    ];

    // ── Lifecycle states (customer-safe vocabulary) ──────────────────────────
    public const STATE_PENDING_SETUP  = 'pending_setup';   // created locally, provider not yet called
    public const STATE_AWAITING_DNS   = 'awaiting_dns';    // hostname created; customer must add DNS/TXT
    public const STATE_VALIDATING     = 'validating';      // ownership check in progress
    public const STATE_SSL_PENDING    = 'ssl_pending';     // ownership ok; certificate issuing
    public const STATE_ACTIVE         = 'active';          // ownership + SSL + routing all live
    public const STATE_FAILED         = 'failed';          // a step failed; last_error explains
    public const STATE_DISCONNECTING  = 'disconnecting';   // delete in flight
    public const STATE_DISCONNECTED   = 'disconnected';    // fully removed

    public const LIVE_STATES = [
        self::STATE_PENDING_SETUP, self::STATE_AWAITING_DNS, self::STATE_VALIDATING,
        self::STATE_SSL_PENDING, self::STATE_ACTIVE, self::STATE_FAILED,
    ];

    public function isLive(): bool
    {
        return in_array($this->state, self::LIVE_STATES, true);
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    /**
     * Customer-facing one-liner for the current state (no Cloudflare jargon).
     */
    public function customerStatusLabel(): string
    {
        return [
            self::STATE_PENDING_SETUP => 'Getting ready',
            self::STATE_AWAITING_DNS  => 'Waiting for your DNS change',
            self::STATE_VALIDATING    => 'Checking your domain',
            self::STATE_SSL_PENDING   => 'Issuing your SSL certificate',
            self::STATE_ACTIVE        => 'Connected',
            self::STATE_FAILED        => 'Needs attention',
            self::STATE_DISCONNECTING => 'Disconnecting',
            self::STATE_DISCONNECTED  => 'Disconnected',
        ][$this->state] ?? 'In progress';
    }
}
