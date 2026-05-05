<?php

namespace App\Core\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Credit;
use App\Models\User;
use App\Models\Workspace;
use App\Core\Audit\AuditLogService;
use App\Core\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;

class StripeService
{
    private string $secretKey;
    private string $webhookSecret;
    private bool $enabled;

    public function __construct(
        private AuditLogService $auditLog,
        private NotificationService $notifications,
    )
    {
        $this->secretKey = config('billing.stripe.secret_key', env('STRIPE_SECRET_KEY', ''));
        $this->webhookSecret = config('billing.stripe.webhook_secret', env('STRIPE_WEBHOOK_SECRET', ''));
        // v5.5.4 — reject obviously-broken prefix-only keys so dev mode kicks
        // in gracefully instead of 401ing inside a Stripe call. A real Stripe key
        // is ≥24 chars long — anything shorter is a placeholder.
        $this->enabled = ! empty($this->secretKey)
            && strlen($this->secretKey) >= 24
            && (str_starts_with($this->secretKey, 'sk_test_') || str_starts_with($this->secretKey, 'sk_live_'));
    }

    /**
     * Create a Stripe Checkout session for plan subscription.
     */
    public function createCheckoutSession(int $workspaceId, int $planId, int $userId): array
    {
        $plan = Plan::findOrFail($planId);
        $workspace = Workspace::findOrFail($workspaceId);
        $user = User::find($userId);

        if (! $this->enabled) {
            // Development mode — activate plan without payment
            return $this->devActivate($workspaceId, $planId, $userId);
        }

        // Per-plan pre-created Stripe Price ID required (v5.5.4)
        if (empty($plan->stripe_price_id)) {
            return ['error' => 'No Stripe price configured for this plan. Run plan seeding first.', 'plan' => $plan->slug];
        }

        try {
            $stripe = new \Stripe\StripeClient($this->secretKey);

            // Step 1: get or create the Stripe Customer for this workspace
            $existingSub = Subscription::where('workspace_id', $workspaceId)
                ->whereNotNull('stripe_customer_id')
                ->latest()
                ->first();
            $customerId = $existingSub?->stripe_customer_id;

            if (! $customerId) {
                $customer = $stripe->customers->create([
                    'email'    => $user?->email,
                    'name'     => $user?->name,
                    'metadata' => [
                        'workspace_id' => (string) $workspaceId,
                        'user_id'      => (string) $userId,
                    ],
                ]);
                $customerId = $customer->id;
            }

            // Step 2: create the Checkout Session using the pre-created Price
            $session = $stripe->checkout->sessions->create([
                'mode'        => 'subscription',
                'customer'    => $customerId,
                'line_items'  => [[
                    'price'    => $plan->stripe_price_id,
                    'quantity' => 1,
                ]],
                'subscription_data' => [
                    'trial_period_days' => 3,
                    'metadata' => [
                        'workspace_id' => (string) $workspaceId,
                        'plan_id'      => (string) $planId,
                        'plan_slug'    => (string) $plan->slug,
                    ],
                ],
                'metadata' => [
                    'workspace_id' => (string) $workspaceId,
                    'plan_id'      => (string) $planId,
                    'user_id'      => (string) $userId,
                    'plan_slug'    => (string) $plan->slug,
                ],
                'success_url' => config('app.url') . '/app/#billing?success=1&session={CHECKOUT_SESSION_ID}',
                'cancel_url'  => config('app.url') . '/app/#billing?cancelled=1',
            ]);

            return [
                'checkout_url' => $session->url,
                'session_id'   => $session->id,
                'customer_id'  => $customerId,
            ];
        } catch (\Throwable $e) {
            Log::error('StripeService::createCheckoutSession failed', [
                'workspace_id' => $workspaceId, 'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return ['error' => 'Could not start checkout: ' . $e->getMessage()];
        }
    }

    /**
     * Handle Stripe webhook events.
     * Idempotent: checks if the event has already been processed before acting.
     */
    public function handleWebhook(string $payload, string $signature): array
    {
        if (! $this->enabled) {
            return ['handled' => false, 'reason' => 'Stripe not configured'];
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        } catch (\Throwable $e) {
            return ['handled' => false, 'error' => 'Invalid webhook signature'];
        }

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;

                // FIX-A: Idempotency pivoted to session->id (always present at event time).
                //
                // The previous check used stripe_subscription_id, which is not written until
                // handleCheckoutCompleted() commits. On Stripe's webhook retry (fired before
                // the first delivery finishes), the check returns false → double subscription
                // creation and double credit grant.
                //
                // Correct anchor: provider_subscription_id = 'stripe_session:{session_id}'.
                // This value is written atomically inside handleCheckoutCompleted() in a
                // DB transaction BEFORE any credits or subscriptions are created, making
                // the dedup check race-safe.
                $sessionKey = 'stripe_session:' . ($session->id ?? '');
                $alreadyDone = $sessionKey !== 'stripe_session:' &&
                    Subscription::where('provider_subscription_id', $sessionKey)->exists();

                if ($alreadyDone) {
                    return ['handled' => true, 'action' => 'already_processed', 'type' => $event->type];
                }

                return $this->handleCheckoutCompleted($session);

            case 'invoice.paid':
                return $this->handleInvoicePaid($event->data->object);

            case 'invoice.payment_failed':
                return $this->handlePaymentFailed($event->data->object);

            case 'customer.subscription.deleted':
                return $this->handleSubscriptionCancelled($event->data->object);

            case 'customer.subscription.updated':
                return $this->handleSubscriptionUpdated($event->data->object);

            default:
                return ['handled' => false, 'type' => $event->type];
        }
    }

    /**
     * Upgrade or downgrade a subscription to a new plan.
     * In Stripe: updates the subscription item price.
     * In dev mode: directly activates the new plan.
     */
    public function changePlan(int $workspaceId, int $newPlanId, int $userId): array
    {
        $newPlan = Plan::findOrFail($newPlanId);
        $currentSub = Subscription::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->first();

        if (! $this->enabled || ! ($currentSub?->stripe_subscription_id)) {
            // Dev mode or no Stripe subscription — direct swap
            return $this->devActivate($workspaceId, $newPlanId, $userId);
        }

        try {
            $stripe = new \Stripe\StripeClient($this->secretKey);
            $stripeSub = $stripe->subscriptions->retrieve($currentSub->stripe_subscription_id);

            // Update the subscription item with the new plan price
            // In production you'd use Stripe Price IDs stored in the plans table
            // For now: cancel current and create new checkout session
            $stripe->subscriptions->cancel($currentSub->stripe_subscription_id, ['prorate' => true]);

            // Create new checkout for new plan
            return $this->createCheckoutSession($workspaceId, $newPlanId, $userId);

        } catch (\Throwable $e) {
            Log::error('StripeService::changePlan failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get or create Stripe Customer Portal URL for self-service billing.
     * Allows customers to update payment methods, view invoices, cancel.
     */
    public function getPortalUrl(int $workspaceId, int $userId): array
    {
        if (! $this->enabled) {
            return ['success' => false, 'error' => 'Stripe not configured in this environment'];
        }

        $sub = Subscription::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->whereNotNull('stripe_customer_id')
            ->first();

        if (! $sub) {
            return ['success' => false, 'error' => 'No Stripe subscription found. Please subscribe to a plan first.'];
        }

        try {
            $stripe = new \Stripe\StripeClient($this->secretKey);
            $session = $stripe->billingPortal->sessions->create([
                'customer'   => $sub->stripe_customer_id,
                'return_url' => config('app.url') . '/app',
            ]);

            return ['success' => true, 'portal_url' => $session->url];
        } catch (\Throwable $e) {
            Log::error('StripeService::getPortalUrl failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Failed to create billing portal session'];
        }
    }

    /**
     * Get current subscription and billing status for a workspace.
     */
    public function getBillingStatus(int $workspaceId): array
    {
        $sub = Subscription::where('workspace_id', $workspaceId)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->with('plan')
            ->latest()
            ->first();

        $credit = Credit::where('workspace_id', $workspaceId)->first();

        // Best-effort live data from Stripe for trial + cancellation flags.
        $trialEndsAt = null; $cancelAtPeriodEnd = false; $currentPeriodEnd = $sub?->ends_at?->toISOString();
        if ($this->enabled && $sub?->stripe_subscription_id) {
            try {
                $stripe = new \Stripe\StripeClient($this->secretKey);
                $stripeSub = $stripe->subscriptions->retrieve($sub->stripe_subscription_id);
                $trialEndsAt = $stripeSub->trial_end ? date('c', $stripeSub->trial_end) : null;
                $cancelAtPeriodEnd = (bool) ($stripeSub->cancel_at_period_end ?? false);
                if ($stripeSub->current_period_end) $currentPeriodEnd = date('c', $stripeSub->current_period_end);
            } catch (\Throwable $e) {
                Log::warning('StripeService::getBillingStatus stripe fetch failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'has_subscription'       => $sub !== null,
            'plan'                   => $sub?->plan?->name ?? 'Free',
            'plan_slug'              => $sub?->plan?->slug ?? 'free',
            'plan_id'                => $sub?->plan?->id,
            'plan_price'             => (float) ($sub?->plan?->price ?? 0),
            'status'                 => $sub?->status ?? 'active',
            'stripe_connected'       => !empty($sub?->stripe_subscription_id),
            'stripe_customer_id'     => $sub?->stripe_customer_id,
            'starts_at'              => $sub?->starts_at?->toISOString(),
            'ends_at'                => $sub?->ends_at?->toISOString(),
            'current_period_end'     => $currentPeriodEnd,
            'trial_ends_at'          => $trialEndsAt,
            'cancel_at_period_end'   => $cancelAtPeriodEnd,
            'credit_balance'         => (int) ($credit?->balance ?? 0),
            'credit_reserved'        => (int) ($credit?->reserved_balance ?? 0),
            'credit_available'       => max(0, (int)($credit?->balance ?? 0) - (int)($credit?->reserved_balance ?? 0)),
            'monthly_credit_limit'   => (int) ($sub?->plan?->credit_limit ?? 0),
            'stripe_configured'      => $this->enabled,
        ];
    }

    /**
     * Cancel a subscription.
     */
    public function cancel(int $workspaceId): array
    {
        $sub = Subscription::where('workspace_id', $workspaceId)->where('status', 'active')->first();
        if (! $sub) {
            return ['success' => false, 'error' => 'No active subscription'];
        }

        if ($this->enabled && $sub->stripe_subscription_id) {
            $stripe = new \Stripe\StripeClient($this->secretKey);
            $stripe->subscriptions->cancel($sub->stripe_subscription_id);
        }

        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        // Downgrade to free plan
        $freePlan = Plan::where('slug', 'free')->first();
        if ($freePlan) {
            Subscription::create([
                'workspace_id' => $workspaceId,
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'starts_at' => now(),
            ]);
        }

        return ['success' => true];
    }

    /**
     * Add agent add-on to subscription.
     */
    public function addAgentAddon(int $workspaceId, string $agentSlug): array
    {
        $sub = Subscription::where('workspace_id', $workspaceId)->where('status', 'active')->with('plan')->first();
        if (! $sub || ! $sub->plan) {
            return ['success' => false, 'error' => 'No active subscription'];
        }

        $addonPrice = $sub->plan->agent_addon_price;
        if (! $addonPrice) {
            return ['success' => false, 'error' => 'Plan does not support agent add-ons'];
        }

        // In production: create Stripe subscription item for the add-on
        // For now: just enable the agent
        $agent = \App\Models\Agent::where('slug', $agentSlug)->first();
        if ($agent) {
            \DB::table('workspace_agents')->updateOrInsert(
                ['workspace_id' => $workspaceId, 'agent_id' => $agent->id],
                ['enabled' => true]
            );
        }

        return ['success' => true, 'agent' => $agentSlug, 'monthly_cost' => $addonPrice];
    }

    // ═══════════════════════════════════════════════════════════
    // CHATBOT888 ADD-ON 2026-05-02 — Stripe subscription items.
    //
    // Per Phase 0 design call D1: a single Stripe subscription with multiple
    // items (one for the base plan, one for chatbot addon when purchased).
    // Native Stripe pattern with correct proration on add/remove.
    // ═══════════════════════════════════════════════════════════

    /**
     * Add the Chatbot888 add-on to the workspace's active Stripe subscription.
     * Returns ['success' => bool, 'item_id' => str | null, 'error' => str | null].
     */
    public function addChatbotAddon(int $workspaceId, int $userId): array
    {
        $sub = Subscription::where('workspace_id', $workspaceId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()->first();
        if (! $sub) {
            return ['success' => false, 'error' => 'No active subscription. Please subscribe to a plan first.'];
        }
        // Idempotency: if the workspace already has the add-on, return the
        // existing item id without making any new Stripe / DB writes.
        if ($sub->chatbot_addon_item_id) {
            return ['success' => true, 'item_id' => $sub->chatbot_addon_item_id, 'already_active' => true];
        }
        if (! $this->enabled || ! $sub->stripe_subscription_id) {
            // Dev / non-Stripe path — synthesize a local item id for entitlement.
            $localItem = 'local_addon_' . uniqid();
            $sub->update(['chatbot_addon_item_id' => $localItem]);
            return ['success' => true, 'item_id' => $localItem, 'dev_mode' => true];
        }

        $addonPriceId = config('billing.chatbot_addon_price_id', env('CHATBOT_ADDON_PRICE_ID', ''));
        if (! $addonPriceId) {
            return ['success' => false, 'error' => 'Chatbot add-on price not configured.'];
        }

        try {
            $stripe = new \Stripe\StripeClient($this->secretKey);
            $newItem = $stripe->subscriptionItems->create([
                'subscription' => $sub->stripe_subscription_id,
                'price'        => $addonPriceId,
                'quantity'     => 1,
                'proration_behavior' => 'create_prorations',
                'metadata' => [
                    'workspace_id' => (string) $workspaceId,
                    'kind'         => 'chatbot_addon',
                ],
            ]);

            $sub->update(['chatbot_addon_item_id' => $newItem->id]);

            $this->auditLog->log($workspaceId, $userId, 'billing.chatbot_addon_added', 'Subscription', $sub->id, [
                'stripe_subscription_id' => $sub->stripe_subscription_id,
                'item_id'                => $newItem->id,
            ]);

            return ['success' => true, 'item_id' => $newItem->id];
        } catch (\Throwable $e) {
            Log::error('StripeService::addChatbotAddon failed', [
                'workspace_id' => $workspaceId, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Remove the Chatbot888 add-on from the workspace's active Stripe subscription.
     */
    public function removeChatbotAddon(int $workspaceId, int $userId): array
    {
        $sub = Subscription::where('workspace_id', $workspaceId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest()->first();
        if (! $sub) {
            return ['success' => false, 'error' => 'No active subscription.'];
        }
        if (! $sub->chatbot_addon_item_id) {
            return ['success' => true, 'already_removed' => true];
        }

        $itemId = $sub->chatbot_addon_item_id;

        // Local-only dev item — just clear the column.
        if (str_starts_with($itemId, 'local_addon_') || ! $this->enabled) {
            $sub->update(['chatbot_addon_item_id' => null]);
            return ['success' => true, 'dev_mode' => true];
        }

        try {
            $stripe = new \Stripe\StripeClient($this->secretKey);
            $stripe->subscriptionItems->delete($itemId, [
                'proration_behavior' => 'create_prorations',
            ]);
            $sub->update(['chatbot_addon_item_id' => null]);

            $this->auditLog->log($workspaceId, $userId, 'billing.chatbot_addon_removed', 'Subscription', $sub->id, [
                'stripe_subscription_id' => $sub->stripe_subscription_id,
                'item_id'                => $itemId,
            ]);

            return ['success' => true];
        } catch (\Throwable $e) {
            Log::error('StripeService::removeChatbotAddon failed', [
                'workspace_id' => $workspaceId, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Private handlers ─────────────────────────────────

    private function handleCheckoutCompleted(object $session): array
    {
        $wsId   = $session->metadata->workspace_id ?? null;
        $planId = $session->metadata->plan_id ?? null;
        $userId = $session->metadata->user_id ?? null;

        if (! $wsId || ! $planId) {
            return ['handled' => false, 'error' => 'Missing metadata'];
        }

        $plan = Plan::find($planId);

        // FIX-A: Write the idempotency anchor atomically BEFORE any credits or
        // subscriptions are created. Uses DB::transaction + insertOrIgnore so:
        //   - First delivery: inserts the row, proceeds to provision
        //   - Concurrent retry: insertOrIgnore is a no-op, returns 0 rows affected → skip
        // provider_subscription_id = 'stripe_session:{cs_xxx}' is the dedup key
        // (stripe_subscription_id may be null at event time, so cannot be used here).
        $sessionKey   = 'stripe_session:' . ($session->id ?? '');
        $provisioned  = false;

        \Illuminate\Support\Facades\DB::transaction(function () use (
            $wsId, $planId, $plan, $session, $sessionKey, $userId, &$provisioned
        ) {
            // Claim the session — if another worker already claimed it, inserted = 0
            $inserted = \Illuminate\Support\Facades\DB::table('subscriptions')->insertOrIgnore([
                'workspace_id'            => $wsId,
                'plan_id'                 => $planId,
                'provider'                => 'stripe',
                'status'                  => 'active',
                'starts_at'               => now(),
                'provider_subscription_id'=> $sessionKey,   // idempotency anchor
                'stripe_subscription_id'  => $session->subscription ?? null,
                'stripe_customer_id'      => $session->customer ?? null,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);

            if ($inserted === 0) {
                // Another worker already provisioned this session — bail cleanly
                return;
            }

            $provisioned = true;

            // Supersede any prior active/trialing subscriptions for this workspace.
            // v5.5.5 — NULL-safe: MySQL returns NULL (not TRUE) for
            // NULL != 'x', so the legacy manual seed subs (provider_subscription_id
            // IS NULL) were never getting superseded. Explicitly include them.
            Subscription::where('workspace_id', $wsId)
                ->whereIn('status', ['active', 'trialing'])
                ->where(function ($q) use ($sessionKey) {
                    $q->whereNull('provider_subscription_id')
                      ->orWhere('provider_subscription_id', '!=', $sessionKey);
                })
                ->update(['status' => 'superseded']);

            // Refresh credits to new plan limit
            if ($plan) {
                Credit::where('workspace_id', $wsId)->lockForUpdate()->first()
                    ? Credit::where('workspace_id', $wsId)
                        ->update(['balance' => $plan->credit_limit, 'reserved_balance' => 0, 'updated_at' => now()])
                    : Credit::create(['workspace_id' => $wsId, 'balance' => $plan->credit_limit, 'reserved_balance' => 0]);
            }
        });

        if (! $provisioned) {
            return ['handled' => true, 'action' => 'already_processed_concurrent', 'type' => 'checkout.session.completed'];
        }

        $this->auditLog->log($wsId, $userId, 'billing.subscription_created', 'Plan', $planId, [
            'plan'           => $plan?->name,
            'stripe_session' => $session->id ?? null,
        ]);

        // SEO-only product mode 2026-05-01: reactivate any wp_site_connections
        // that were billing_suspended by an earlier cancellation. Closes the
        // upgrade-path gap where seo_only → growth via cancel-and-recheckout
        // would leave the WP plugin permanently locked out.
        $this->reactivateBillingForWorkspace((int) $wsId, 'subscription_created_via_checkout');

        return ['handled' => true, 'action' => 'subscription_created', 'plan' => $plan?->slug];
    }

    private function handleInvoicePaid(object $invoice): array
    {
        // Monthly credit refresh on renewal
        $sub = Subscription::where('stripe_subscription_id', $invoice->subscription ?? '')->first();
        if (! $sub) return ['handled' => false, 'reason' => 'subscription_not_found'];

        $plan = Plan::find($sub->plan_id);
        if ($plan) {
            Credit::where('workspace_id', $sub->workspace_id)
                ->update(['balance' => $plan->credit_limit, 'updated_at' => now()]);
        }

        Log::info("Credits refreshed for workspace {$sub->workspace_id} on invoice.paid");
        return ['handled' => true, 'action' => 'credits_refreshed', 'workspace_id' => $sub->workspace_id];
    }

    private function handlePaymentFailed(object $invoice): array
    {
        $sub = Subscription::where('stripe_subscription_id', $invoice->subscription ?? '')->first();
        if ($sub) {
            $sub->update(['status' => 'past_due']);
            Log::warning("Payment failed for workspace {$sub->workspace_id}");

            // LB-Engine17-D
            $this->notifications->send($sub->workspace_id, 'billing', 'subscription.payment_failed', [
                'subscription_id'        => $sub->id,
                'stripe_subscription_id' => $sub->stripe_subscription_id,
            ]);
        }
        return ['handled' => true, 'action' => 'subscription_past_due'];
    }

    private function handleSubscriptionCancelled(object $subscription): array
    {
        $sub = Subscription::where('stripe_subscription_id', $subscription->id)->first();
        if ($sub) {
            $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            // Downgrade to free plan
            $freePlan = Plan::where('slug', 'free')->first();
            if ($freePlan) {
                Subscription::create([
                    'workspace_id' => $sub->workspace_id,
                    'plan_id'      => $freePlan->id,
                    'provider'     => 'system',
                    'status'       => 'active',
                    'starts_at'    => now(),
                ]);
            }

            // Zero credits
            Credit::where('workspace_id', $sub->workspace_id)
                ->update(['balance' => 0, 'reserved_balance' => 0]);

            // P0 hardening 2026-05-01: suspend WP site connections (reversible).
            $this->suspendBillingForWorkspace($sub->workspace_id, 'subscription_cancelled');

            // LB-Engine17-D
            $this->notifications->send($sub->workspace_id, 'billing', 'subscription.cancelled', [
                'subscription_id'        => $sub->id,
                'stripe_subscription_id' => $sub->stripe_subscription_id,
                'downgraded_to'          => 'free',
            ]);
        }

        return ['handled' => true, 'action' => 'subscription_cancelled'];
    }

    private function handleSubscriptionUpdated(object $subscription): array
    {
        $sub = Subscription::where('stripe_subscription_id', $subscription->id)->first();
        if ($sub) {
            $previousStatus = $sub->status;
            $newStatus = match ($subscription->status) {
                'active'   => 'active',
                'past_due' => 'past_due',
                'canceled' => 'cancelled',
                'trialing' => 'trialing',
                default    => $sub->status,
            };
            $sub->update(['status' => $newStatus]);

            // CHATBOT888 2026-05-02 — sync chatbot_addon_item_id from the
            // Stripe items list. If the chatbot price is present, persist the
            // item id; if it's absent, NULL it out. This fires on every items
            // change (add / remove / quantity) because Stripe sends a
            // customer.subscription.updated webhook for those.
            $addonPriceId = config('billing.chatbot_addon_price_id', env('CHATBOT_ADDON_PRICE_ID', ''));
            $items = $subscription->items->data ?? [];
            $foundAddonItemId = null;
            foreach ($items as $item) {
                if (($item->price->id ?? null) === $addonPriceId) {
                    $foundAddonItemId = $item->id;
                    break;
                }
            }
            if ($sub->chatbot_addon_item_id !== $foundAddonItemId) {
                $sub->update(['chatbot_addon_item_id' => $foundAddonItemId]);
                Log::info('[chatbot] addon entitlement sync via webhook', [
                    'workspace_id'  => $sub->workspace_id,
                    'subscription'  => $sub->stripe_subscription_id,
                    'addon_item_id' => $foundAddonItemId,
                    'state'         => $foundAddonItemId ? 'granted' : 'revoked',
                ]);
            }

            // P0 hardening 2026-05-01: suspend / reactivate WP site connections
            // based on subscription status transitions.
            //   - active|trialing → reactivate any billing_suspended sites
            //   - past_due|cancelled → suspend any active sites
            // User-disconnected (status='disconnected') and technical-failed
            // sites are NOT touched by billing transitions.
            if ($previousStatus !== $newStatus) {
                if (in_array($newStatus, ['active', 'trialing'], true)) {
                    $this->reactivateBillingForWorkspace($sub->workspace_id, 'subscription_active');
                } elseif (in_array($newStatus, ['past_due', 'cancelled'], true)) {
                    $this->suspendBillingForWorkspace($sub->workspace_id, 'subscription_' . $newStatus);
                }
            }

            // LB-Engine17-D — fire on status transitions worth surfacing
            if ($previousStatus !== $newStatus) {
                $this->notifications->send($sub->workspace_id, 'billing', 'subscription.upgraded', [
                    'subscription_id'        => $sub->id,
                    'stripe_subscription_id' => $sub->stripe_subscription_id,
                    'previous_status'        => $previousStatus,
                    'new_status'             => $newStatus,
                ]);
            }
        }

        return ['handled' => true, 'action' => 'subscription_updated'];
    }

    /**
     * P0 hardening 2026-05-01 helpers — suspend / reactivate WP site connections
     * for a workspace when its subscription state changes.
     */
    private function suspendBillingForWorkspace(int $workspaceId, string $reason): void
    {
        try {
            $count = \DB::table('wp_site_connections')
                ->where('workspace_id', $workspaceId)
                ->where('status', \App\Models\WpSiteConnection::STATUS_ACTIVE)
                ->update([
                    'status'     => \App\Models\WpSiteConnection::STATUS_BILLING_SUSPENDED,
                    'updated_at' => now(),
                ]);
            if ($count > 0) {
                \Log::info('[stripe-billing] suspended WP connections', [
                    'workspace_id' => $workspaceId,
                    'count'        => $count,
                    'reason'       => $reason,
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('[stripe-billing] suspend failed (non-fatal)', [
                'workspace_id' => $workspaceId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function reactivateBillingForWorkspace(int $workspaceId, string $reason): void
    {
        try {
            // Restore ONLY billing-suspended; leave user-disconnected and failed alone.
            $count = \DB::table('wp_site_connections')
                ->where('workspace_id', $workspaceId)
                ->where('status', \App\Models\WpSiteConnection::STATUS_BILLING_SUSPENDED)
                ->update([
                    'status'     => \App\Models\WpSiteConnection::STATUS_ACTIVE,
                    'updated_at' => now(),
                ]);
            if ($count > 0) {
                \Log::info('[stripe-billing] reactivated WP connections', [
                    'workspace_id' => $workspaceId,
                    'count'        => $count,
                    'reason'       => $reason,
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('[stripe-billing] reactivate failed (non-fatal)', [
                'workspace_id' => $workspaceId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function devActivate(int $wsId, int $planId, int $userId): array
    {
        Subscription::where('workspace_id', $wsId)->where('status', 'active')
            ->update(['status' => 'superseded']);

        Subscription::create([
            'workspace_id' => $wsId, 'plan_id' => $planId,
            'status' => 'active', 'starts_at' => now(),
        ]);

        $plan = Plan::find($planId);
        if ($plan) {
            Credit::updateOrCreate(
                ['workspace_id' => $wsId],
                ['balance' => $plan->credit_limit, 'reserved_balance' => 0]
            );
        }

        return ['checkout_url' => null, 'dev_mode' => true, 'activated' => true, 'plan' => $plan?->name];
    }
}
