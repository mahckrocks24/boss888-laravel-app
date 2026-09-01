<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

    /**
     * MONEY-1 (2026-08-29) — the ONE place that answers "which plan is this workspace entitled to?".
     *
     * Before: PlanGatingService (the execution kernel's gate), BuilderService (website limit) and
     * SeoService each re-implemented the lookup with `status = 'active'` only, while
     * FeatureGateService and ArthurService counted 'trialing' too. A 3-day-trial customer (trialing
     * Growth, 50 credits) therefore asked Sarah for an article and got task 31809 FAILED with
     * "AI features require AI Lite plan or above" — the trial that exists to sell the AI tier could
     * not use AI. Website-workspaces (billing_workspace_id) were also resolved against their own
     * inherited row, which goes stale on upgrade; the pool workspace is the truth.
     */
    public const ENTITLED_STATUSES = ['active', 'trialing'];

    /** Subscriptions that currently confer entitlement (a trial only until it ends). */
    public function scopeEntitled($query)
    {
        return $query->whereIn('status', self::ENTITLED_STATUSES)
            ->where(function ($w) {
                $w->where('status', 'active')->orWhereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    /** The workspace whose subscription/credits govern $wsId (website = workspace architecture). */
    public static function billingWorkspaceIdFor(int $wsId): int
    {
        $pool = (int) \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->value('billing_workspace_id');
        return $pool > 0 ? $pool : $wsId;
    }

    public static function entitledFor(int $wsId): ?self
    {
        // INC-0006: entitlement follows the BILLING POOL, so cancelling the subscription row on an
        // archived failed-build workspace achieved nothing on its own — the workspace simply inherited
        // its parent's plan and kept every feature. A workspace that was archived because its build
        // never produced a website is not a business, and no pool may lend it entitlement.
        //
        // Deliberately archived-only: a QA fixture is also non-active, but fixtures exist to be
        // exercised and must keep the plan they were set up with.
        $state = DB::table('workspaces')->where('id', $wsId)->value('lifecycle_state');
        if ($state === \App\Models\Workspace::STATE_ARCHIVED) {
            return null;
        }

        return static::where('workspace_id', self::billingWorkspaceIdFor($wsId))->entitled()->orderByDesc('id')->first();
    }

    /** Entitled plan, defaulting to Free. Never null when a Free plan row exists. */
    public static function entitledPlanFor(int $wsId): ?Plan
    {
        $sub  = self::entitledFor($wsId);
        $plan = $sub ? Plan::find($sub->plan_id) : null;

        // An archived failed build falls through to the free plan like any unsubscribed workspace;
        // what it must never do is inherit the paid plan of the pool it was created under.
        return $plan ?: Plan::where('slug', 'free')->first();
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
