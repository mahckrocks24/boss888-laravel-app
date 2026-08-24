<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A domain the customer OWNS. Living state, synced from the registrar.
 *
 * Distinct from CustomDomain (custom_domains), which is a customer's EXISTING
 * domain pointed at a LevelUp site via Cloudflare for SaaS. This table is for
 * domains we registered on their behalf. A domain can legitimately appear in
 * both: bought here, then connected to a website there.
 */
class CustomerDomain extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'registered_at'          => 'datetime',
        'expires_at'             => 'datetime',
        'last_synced_at'         => 'datetime',
        'auto_renew'             => 'boolean',
        'is_locked'              => 'boolean',
        'whois_privacy'          => 'boolean',
        'nameservers_json'       => 'array',
        'provider_metadata_json' => 'array',
    ];

    public const STATUS_ACTIVE           = 'active';
    public const STATUS_EXPIRED          = 'expired';
    public const STATUS_PENDING_TRANSFER = 'pending_transfer';
    public const STATUS_SUSPENDED        = 'suspended';
    public const STATUS_RELEASED         = 'released';

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(DomainOrderItem::class, 'domain_order_item_id');
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function nameservers(): array
    {
        return $this->nameservers_json ?? [];
    }

    /** Days until expiry. Negative when already expired. Null when unknown. */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }

    public function isExpiringSoon(int $withinDays = 30): bool
    {
        $days = $this->daysUntilExpiry();

        return $days !== null && $days >= 0 && $days <= $withinDays;
    }

    /**
     * The renewal date shown to a customer. Namecheap expires the domain at
     * expires_at, so renewal must happen before then, not on the day.
     */
    public function renewalDate(): ?string
    {
        return $this->expires_at?->toDateString();
    }

    public function customerStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE           => $this->isExpiringSoon() ? 'Expiring soon' : 'Active',
            self::STATUS_EXPIRED          => 'Expired',
            self::STATUS_PENDING_TRANSFER => 'Transfer in progress',
            self::STATUS_SUSPENDED        => 'Suspended',
            self::STATUS_RELEASED         => 'Released',
            default                       => ucfirst((string) $this->status),
        };
    }
}
