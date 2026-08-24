<?php

namespace App\Engines\Infrastructure\Email\Onboarding;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INFRA888 · E7.3 — a LevelUp setup link, not a password.
 *
 * The raw token exists for exactly as long as it takes to build one email. What
 * lives here is its SHA-256 hash, so a database disclosure yields nothing
 * replayable. The customer's chosen password never touches this table in any
 * form — not stored, not hashed, not truncated, not counted.
 */
class MailboxSetupToken extends Model
{
    use BelongsToWorkspace;

    public const PURPOSE_SETUP = 'mailbox_password_setup';
    public const PURPOSE_RESET = 'mailbox_password_reset';

    /** 72 hours, per the approved architecture. */
    public const TTL_HOURS = 72;

    protected $table = 'email_mailbox_setup_tokens';

    protected $fillable = [
        'workspace_id', 'email_domain_id', 'email_mailbox_id',
        'token_hash', 'purpose', 'recipient_email',
        'expires_at', 'consumed_at', 'superseded_at', 'superseded_reason',
        'issued_by_user_id', 'send_count', 'last_sent_at',
    ];

    /** The hash is not secret, but it has no business leaving the server. */
    protected $hidden = ['token_hash'];

    protected $casts = [
        'workspace_id'      => 'integer',
        'email_domain_id'   => 'integer',
        'email_mailbox_id'  => 'integer',
        'issued_by_user_id' => 'integer',
        'send_count'        => 'integer',
        'expires_at'        => 'datetime',
        'consumed_at'       => 'datetime',
        'superseded_at'     => 'datetime',
        'last_sent_at'      => 'datetime',
    ];

    public static function purposes(): array
    {
        return [self::PURPOSE_SETUP, self::PURPOSE_RESET];
    }

    /**
     * The only way a raw token becomes a lookup key.
     *
     * SHA-256 rather than a password hash: this is a 256-bit random value, not
     * a human-chosen secret, so there is nothing to brute force and a slow hash
     * would only add latency to every click.
     */
    public static function hash(#[\SensitiveParameter] string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(EmailMailbox::class, 'email_mailbox_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /**
     * Usable means every condition holds. Written as one expression so a future
     * edit cannot satisfy three of four and quietly accept a dead token.
     */
    public function isUsable(): bool
    {
        return ! $this->isConsumed()
            && ! $this->isSuperseded()
            && ! $this->isExpired();
    }

    /** Why a token was refused — for the customer-safe message, never a hint. */
    public function refusalReason(): ?string
    {
        return match (true) {
            $this->isConsumed()   => 'already_used',
            $this->isSuperseded() => 'replaced',
            $this->isExpired()    => 'expired',
            default               => null,
        };
    }

    /** Safe for an operator surface. Carries no token and no password. */
    public function toSafeArray(): array
    {
        return [
            'id'              => $this->id,
            'mailbox_id'      => $this->email_mailbox_id,
            'purpose'         => $this->purpose,
            'recipient'       => $this->recipient_email,
            'expires_at'      => optional($this->expires_at)->toIso8601String(),
            'consumed_at'     => optional($this->consumed_at)->toIso8601String(),
            'superseded_at'   => optional($this->superseded_at)->toIso8601String(),
            'send_count'      => $this->send_count,
            'usable'          => $this->isUsable(),
            'refusal_reason'  => $this->refusalReason(),
        ];
    }
}
