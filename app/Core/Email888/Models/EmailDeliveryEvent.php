<?php

namespace App\Core\Email888\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EMAIL888 — an immutable provider event. Written once, never edited.
 */
class EmailDeliveryEvent extends Model
{
    protected $table = 'email_delivery_events';

    protected $fillable = [
        'email_delivery_id', 'provider', 'provider_message_id',
        'event_type', 'provider_event_type', 'occurred_at',
        'payload_digest', 'raw_metadata',
    ];

    protected $casts = [
        'occurred_at'  => 'datetime',
        'raw_metadata' => 'array',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(EmailDelivery::class, 'email_delivery_id');
    }
}
