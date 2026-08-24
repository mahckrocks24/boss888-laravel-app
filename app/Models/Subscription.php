<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Throwable;

class Subscription extends Model
{
    protected $fillable = [
        'workspace_id', 'plan_id', 'provider', 'provider_subscription_id',
        'stripe_subscription_id', 'stripe_customer_id',
        'status', 'starts_at', 'ends_at', 'cancelled_at',
        'chatbot_addon_item_id', 'chatbot_addon_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'    => 'datetime',
            'ends_at'      => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * ADS888 — drop the cached ad-eligibility decision whenever a subscription
     * changes.
     *
     * WHY IT LIVES HERE AND NOT IN StripeService
     * A plan can change from several places: the Stripe webhook handlers,
     * changePlan(), cancel(), devActivate(), and workspace provisioning in
     * ArthurService. Wiring each one invites the failure where a new path is
     * added later and quietly stops removing ads. A model hook fires for every
     * create, update and delete regardless of caller, so it cannot be bypassed
     * or forgotten.
     *
     * WHAT IT FIXES
     * AdGateService caches "is this workspace ad-eligible" for 60 seconds. Without
     * invalidation an upgraded customer keeps seeing advertisements until that
     * TTL expires. With it, the gate re-reads on the next request and the ad is
     * gone — the promise the whole product rests on.
     *
     * FAILS OPEN, DELIBERATELY: an error here must never block a billing write.
     * The 60-second TTL remains the backstop, so the worst case is the old
     * behaviour rather than a failed upgrade.
     */
    protected static function booted(): void
    {
        $forget = static function (self $subscription): void {
            try {
                $workspaceId = (int) $subscription->workspace_id;

                if ($workspaceId > 0) {
                    app(\App\Engines\Ads\Services\AdGateService::class)->forgetWorkspace($workspaceId);
                }
            } catch (Throwable $e) {
                Log::warning('ADS888: ad gate cache invalidation failed on subscription change', [
                    'workspace_id' => $subscription->workspace_id ?? null,
                    'error'        => $e->getMessage(),
                ]);
            }
        };

        static::created($forget);
        static::updated($forget);
        static::deleted($forget);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
