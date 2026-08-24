<?php

namespace App\Core\Email888\Models;

use App\Core\Email888\States\DeliveryState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EMAIL888 — one row per outbound message, from hand-off to terminal state.
 */
class EmailDelivery extends Model
{
    protected $table = 'email_deliveries';

    protected $fillable = [
        'correlation_id', 'idempotency_key', 'workspace_id', 'user_id',
        'purpose', 'stream_class',
        'provider', 'provider_message_id', 'provider_stream',
        'sender_address', 'sender_name', 'recipient_address', 'subject',
        'state',
        'queued_at', 'accepted_at', 'delivered_at', 'deferred_at',
        'bounced_at', 'suppressed_at', 'failed_at',
        'failure_category', 'retryable',
        'provider_response', 'metadata',
    ];

    protected $casts = [
        'queued_at'         => 'datetime',
        'accepted_at'       => 'datetime',
        'delivered_at'      => 'datetime',
        'deferred_at'       => 'datetime',
        'bounced_at'        => 'datetime',
        'suppressed_at'     => 'datetime',
        'failed_at'         => 'datetime',
        'retryable'         => 'boolean',
        'provider_response' => 'array',
        'metadata'          => 'array',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(EmailDeliveryEvent::class, 'email_delivery_id');
    }

    public function deliveryState(): DeliveryState
    {
        return DeliveryState::tryFrom((string) $this->state) ?? DeliveryState::QUEUED;
    }

    /**
     * Admin-safe projection. Deliberately omits nothing sensitive because
     * nothing sensitive is stored — but it stays explicit so a later column
     * cannot leak by being added to $fillable.
     */
    public function toAdminArray(): array
    {
        return [
            'id'                  => $this->id,
            'correlation_id'      => $this->correlation_id,
            'workspace_id'        => $this->workspace_id,
            'purpose'             => $this->purpose,
            'stream_class'        => $this->stream_class,
            'provider'            => $this->provider,
            'provider_message_id' => $this->provider_message_id,
            'sender'              => $this->sender_address,
            'recipient'           => $this->recipient_address,
            'subject'             => $this->subject,
            'state'               => $this->state,
            'is_terminal'         => $this->deliveryState()->isTerminal(),
            'failure_category'    => $this->failure_category,
            'retryable'           => (bool) $this->retryable,
            'queued_at'           => optional($this->queued_at)->toIso8601String(),
            'accepted_at'         => optional($this->accepted_at)->toIso8601String(),
            'delivered_at'        => optional($this->delivered_at)->toIso8601String(),
            'bounced_at'          => optional($this->bounced_at)->toIso8601String(),
            'suppressed_at'       => optional($this->suppressed_at)->toIso8601String(),
            'failed_at'           => optional($this->failed_at)->toIso8601String(),
            'provider_response'   => $this->provider_response,
        ];
    }
}
