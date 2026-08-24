<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's domain purchase. Immutable commercial history once paid.
 */
class DomainOrder extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'paid_at'          => 'datetime',
        'metadata_json'    => 'array',
        'subtotal_minor'   => 'integer',
        'tax_minor'        => 'integer',
        'total_minor'      => 'integer',
        'cost_total_minor' => 'integer',
    ];

    public const STATUS_PENDING           = 'pending';
    public const STATUS_AWAITING_PAYMENT  = 'awaiting_payment';
    public const STATUS_PAID              = 'paid';
    public const STATUS_PROVISIONING      = 'provisioning';
    public const STATUS_COMPLETED         = 'completed';
    public const STATUS_PARTIAL           = 'partially_completed';
    public const STATUS_FAILED            = 'failed';
    public const STATUS_CANCELLED         = 'cancelled';

    public function items(): HasMany
    {
        return $this->hasMany(DomainOrderItem::class);
    }

    /** Tenancy helper -- every customer-facing query must go through this. */
    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /** Margin on this order, in minor units. */
    public function marginMinor(): int
    {
        return (int) $this->subtotal_minor - (int) $this->cost_total_minor;
    }

    /**
     * Derive the order status from its items. Called after every registration
     * attempt so the order reflects reality rather than an optimistic guess.
     */
    public function recomputeStatus(): string
    {
        $items = $this->items()->get();

        if ($items->isEmpty()) {
            return $this->status;
        }

        $registered = $items->where('status', DomainOrderItem::STATUS_REGISTERED)->count();
        $failed = $items->whereIn('status', [DomainOrderItem::STATUS_FAILED, DomainOrderItem::STATUS_REFUND_DUE])->count();
        $total = $items->count();

        $status = match (true) {
            $registered === $total => self::STATUS_COMPLETED,
            $failed === $total     => self::STATUS_FAILED,
            $registered + $failed === $total => self::STATUS_PARTIAL,
            default                => self::STATUS_PROVISIONING,
        };

        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }

        return $status;
    }
}
