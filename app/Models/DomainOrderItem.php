<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One domain within an order. Cost, markup and retail are frozen at purchase
 * time -- they are the commercial record, not a live calculation.
 */
class DomainOrderItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'registered_at'          => 'datetime',
        'provider_metadata_json' => 'array',
        'is_premium'             => 'boolean',
        'years'                  => 'integer',
        'attempts'               => 'integer',
        'registrar_cost_minor'   => 'integer',
        'markup_minor'           => 'integer',
        'retail_minor'           => 'integer',
    ];

    public const STATUS_PENDING     = 'pending';
    public const STATUS_REGISTERING = 'registering';
    public const STATUS_REGISTERED  = 'registered';
    public const STATUS_FAILED      = 'failed';
    /** Paid for, but the registrar refused permanently. Owed back to the customer. */
    public const STATUS_REFUND_DUE  = 'refund_due';

    public function order(): BelongsTo
    {
        return $this->belongsTo(DomainOrder::class, 'domain_order_id');
    }

    public function customerDomain(): BelongsTo
    {
        return $this->belongsTo(CustomerDomain::class, 'id', 'domain_order_item_id');
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function marginMinor(): int
    {
        return (int) $this->retail_minor - (int) $this->registrar_cost_minor;
    }

    /**
     * The idempotency key handed to the registrar. Deterministic: the same item
     * always produces the same key, so a retry can never be mistaken for a new
     * purchase.
     */
    public function registrarIdempotencyKey(): string
    {
        return 'lvl-order-' . $this->domain_order_id . '-item-' . $this->id;
    }
}
