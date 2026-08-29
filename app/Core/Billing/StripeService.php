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

            // MONEY-1 (2026-08-29): ONE 3-day trial per workspace. The platform grants it at signup
            // (TrialService); a workspace that already used it must not get a second free 3 days
            // from Stripe at checkout — that was a silent revenue leak on every upgrade.
            $subscriptionData = [
                'metadata' => [
                    'workspace_id' => (string) $workspaceId,
                    'plan_id'      => (string) $planId,
                    'plan_slug'    => (string) $plan->slug,
                ],
            ];
            if (! app(\App\Core\Billing\TrialService::class)->hasHadTrial($workspaceId)) {
                $subscriptionData['trial_period_days'] = 3;
            }

            // Step 2: create the Checkout Session using the pre-created Price
            $session = $stripe->checkout->sessions->create([
                'mode'        => 'subscription',
                'customer'    => $customerId,
                'line_items'  => [[
                    'price'    => $plan->stripe_price_id,
                    'quantity' => 1,
                ]],
                'subscription_data' => $subscriptionData,
                'metadata' => [
                    'workspace_id' => (string) $workspaceId,
                    'plan_id'      => (string) $planId,
                    'user_id'      => (string) $userId,
                    'plan_slug'    => (string) $plan->slug,
                ],
                // MONEY-1 (2026-08-29): '/app/#billing?…' landed on the Workspace view (the SPA routes by
                // path, not hash) and the success toast never showed. Return to the billing PAGE.
                'success_url' => config('app.url') . '/app/billing?checkout=success&session={CHECKOUT_SESSION_ID}',
                'cancel_url'  => config('app.url') . '/app/billing?checkout=cancelled',
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
     * Create a Stripe Checkout session for a ONE-TIME domain purchase.
     *
     * Deliberately part of StripeService rather than a parallel billing class:
     * it reuses this object's secret key, customer resolution and webhook
     * signature verification. A second Stripe implementation would be a second
     * place for billing bugs to live.
     *
     * Differences from the subscription flow: mode is 'payment', line items are
     * built from price_data (a domain has no pre-created Stripe Price because
     * every name costs something different), and metadata carries
     * order_type=domain so the webhook can route the event.
     */
    public function createDomainCheckoutSession(\App\Models\DomainOrder $order, ?int $userId = null): array
    {
        if (! $this->enabled) {
            return ['error' => 'Payments are not configured.', 'code' => 'STRIPE_DISABLED'];
        }

        $order->loadMissing('items');

        if ($order->items->isEmpty()) {
            return ['error' => 'Order has no items', 'code' => 'EMPTY_ORDER'];
        }

        $user = $userId ? User::find($userId) : null;

        try {
            $stripe = new \Stripe\StripeClient($this->secretKey);

            // Reuse the workspace's existing Stripe customer when there is one,
            // so a customer's domain purchases and subscription live together.
            $existingSub = Subscription::where('workspace_id', $order->workspace_id)
                ->whereNotNull('stripe_customer_id')
                ->latest()
                ->first();
            $customerId = $existingSub?->stripe_customer_id;

            if (! $customerId) {
                $customer = $stripe->customers->create([
                    'email'    => $user?->email,
                    'name'     => $user?->name,
                    'metadata' => [
                        'workspace_id' => (string) $order->workspace_id,
                        'user_id'      => (string) ($userId ?? ''),
                    ],
                ]);
                $customerId = $customer->id;
            }

            $lineItems = [];

            foreach ($order->items as $item) {
                $years = (int) $item->years;

                $lineItems[] = [
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => strtolower($order->currency ?: 'USD'),
                        'unit_amount'  => (int) $item->retail_minor,
                        'product_data' => [
                            // Customer-facing copy. LevelUp Growth is the seller;
                            // the registrar and the internal engine are never named.
                            'name'        => 'Domain registration - ' . $item->domain,
                            'description' => $years . ' year' . ($years === 1 ? '' : 's')
                                . ' registration, managed by LevelUp Growth',
                        ],
                    ],
                ];
            }

            $session = $stripe->checkout->sessions->create([
                'mode'       => 'payment',
                'customer'   => $customerId,
                'line_items' => $lineItems,
                'payment_intent_data' => [
                    'description' => 'LevelUp Growth domain registration (order #' . $order->id . ')',
                    'metadata'    => [
                        'order_type'      => 'domain',
                        'domain_order_id' => (string) $order->id,
                        'workspace_id'    => (string) $order->workspace_id,
                    ],
                ],
                'metadata' => [
                    'order_type'      => 'domain',
                    'domain_order_id' => (string) $order->id,
                    'workspace_id'    => (string) $order->workspace_id,
                    'user_id'         => (string) ($userId ?? ''),
                ],
                'success_url' => config('app.url') . '/app/#domains?purchase=success&order=' . $order->id,
                'cancel_url'  => config('app.url') . '/app/#domains?purchase=cancelled&order=' . $order->id,
            ]);

            return [
                'checkout_url' => $session->url,
                'session_id'   => $session->id,
                'customer_id'  => $customerId,
                'order_id'     => $order->id,
            ];
        } catch (\Throwable $e) {
            Log::error('StripeService::createDomainCheckoutSession failed', [
                'workspace_id' => $order->workspace_id,
                'order_id'     => $order->id,
                'error'        => $e->getMessage(),
            ]);

            return ['error' => 'Could not start checkout: ' . $e->getMessage()];
        }
    }

    /**
     * Handle Stripe webhook events.
     * Idempotent: checks if the event has already been processed before acting.
     */
    /*
    | ── PHASE 1E.1 OUTCOME CLASSIFICATION ──
    |
    | The route maps these to HTTP status codes. Whether a failure can succeed on a
    | retry is known HERE, by the code that failed; the route only translates it.
    */

    /** Processed, or safely acknowledged. Stripe must not retry. */
    public const OUTCOME_OK = 'ok';

    /** Signature did not verify. Rejected — never acknowledged as received. */
    public const OUTCOME_SIGNATURE_INVALID = 'signature_invalid';

    /** Failed, but a retry can succeed once the cause is cleared. */
    public const OUTCOME_RETRYABLE = 'retryable';

    public const WEBHOOK_FAILURE_STATE_KEY = 'stripe:domain-webhook-failure';
    public const WEBHOOK_SUCCESS_STATE_KEY = 'stripe:domain-webhook-success';

    /**
     * Handle a domain-order checkout completion.
     *
     * ── THE PHASE 1E P0 ──
     *
     * This branch used to call app(DomainCommerceService::class), which is NOT
     * container-resolvable: DomainPricingService requires NamecheapRegistrarConnector,
     * which requires NamecheapClient(string $environment), and nothing binds it. Every
     * genuine domain payment therefore threw BindingResolutionException, the route
     * caught it and answered HTTP 200, and Stripe recorded a successful delivery and
     * never retried. A customer could be charged and the order stay `pending` forever.
     *
     * ::make() is the factory every other caller in this codebase already uses.
     */
    /**
     * The single authorised fixture, or null.
     *
     * EVERY condition must hold. Any blank value, any mismatch, any missing
     * precondition returns null and the request is rejected as an invalid signature.
     * There is no wildcard, no prefix match, and no way for external traffic to reach
     * this path: it requires a loopback source address that only the local replay
     * command produces.
     *
     * Neither secret is ever logged.
     */
    private function verifyAuthorisedFixture(string $payload, string $signature, ?string $sourceIp): ?object
    {
        $cfg = (array) config('domains.fixture_replay', []);

        // 1. explicit flag
        if (($cfg['enabled'] ?? false) !== true) {
            return null;
        }

        // 2. approved environment
        if (! app()->environment((array) ($cfg['environments'] ?? ['staging', 'production']))) {
            return null;
        }

        // 3. loopback origin only — external traffic can never reach this path
        if (! in_array((string) $sourceIp, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        // 4. a secret that exists and is NOT the live webhook secret
        $secret = (string) ($cfg['secret'] ?? '');

        if ($secret === '' || hash_equals($this->webhookSecret, $secret)) {
            return null;
        }

        // 5. every authorised identifier must be configured
        foreach (['event_id', 'session_id', 'payment_intent_id', 'event_type'] as $k) {
            if (($cfg[$k] ?? '') === '') {
                return null;
            }
        }

        if ((int) ($cfg['order_id'] ?? 0) < 1 || (int) ($cfg['workspace_id'] ?? 0) < 1) {
            return null;
        }

        // 6. containment must still be in force
        if (config('domains.fulfilment.enabled') !== false) {
            return null;
        }

        if ((((array) config('platform_events.producers', []))['domain.order.paid'] ?? false) !== true) {
            return null;
        }

        // 7. the signature must verify against the FIXTURE secret, using the real
        //    Stripe verifier — not a hand-rolled comparison.
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $secret);
        } catch (\Throwable) {
            return null;
        }

        // 8. exact event identity, type and test-mode
        if ((string) ($event->id ?? '') !== (string) $cfg['event_id']) {
            return null;
        }

        if ((string) ($event->type ?? '') !== (string) $cfg['event_type']) {
            return null;
        }

        if (($event->livemode ?? true) !== false) {
            return null;
        }

        // 9. exact session and payment intent
        $session = $event->data->object ?? null;

        if ($session === null
            || (string) ($session->id ?? '') !== (string) $cfg['session_id']
            || (string) ($session->payment_intent ?? '') !== (string) $cfg['payment_intent_id']) {
            return null;
        }

        // 10. the session must resolve to the ONE authorised order, in workspace 1
        $order = \App\Models\DomainOrder::where('stripe_session_id', (string) $session->id)->first();

        if ($order === null
            || (int) $order->id !== (int) $cfg['order_id']
            || (int) $order->workspace_id !== (int) $cfg['workspace_id']) {
            return null;
        }

        \Illuminate\Support\Facades\Log::warning('[StripeWebhook] AUTHORISED FIXTURE accepted', [
            'stripe_event_id' => (string) $event->id,
            'order_id' => (int) $order->id,
            'workspace_id' => (int) $order->workspace_id,
            'source_ip' => $sourceIp,
        ]);

        return $event;
    }

    private function handleDomainCheckoutCompleted(object $event, object $session): array
    {
        $sessionId = (string) ($session->id ?? '');
        $intentId = (string) ($session->payment_intent ?? '');

        try {
            $result = \App\Services\Domains\DomainCommerceService::make()
                ->markPaidAndProvision($sessionId, $intentId);
        } catch (\Throwable $e) {
            // A valid payment we could not durably record. NEVER acknowledged as
            // processed: a false 200 here IS the charged-but-unfulfilled incident.
            $this->recordWebhookFailure($event, $session, self::OUTCOME_RETRYABLE, $e);

            return [
                'handled' => false,
                'outcome' => self::OUTCOME_RETRYABLE,
                'reason' => 'internal processing failure',
                'type' => $event->type ?? null,
            ];
        }

        // No order for this session. A valid payment that cannot be associated with an
        // order must fail VISIBLY, so it can be replayed once an operator has fixed the
        // association — not be silently acknowledged.
        if (($result['handled'] ?? false) === false) {
            $this->recordWebhookFailure($event, $session, self::OUTCOME_RETRYABLE, null,
                (string) ($result['reason'] ?? 'unresolved order'));

            return $result + ['outcome' => self::OUTCOME_RETRYABLE, 'type' => $event->type ?? null];
        }

        $this->recordWebhookSuccess($event, $session, $result);

        return $result + ['outcome' => self::OUTCOME_OK, 'type' => $event->type ?? null];
    }

    /**
     * Durable record of a failed domain-payment webhook, for operator visibility.
     *
     * Never throws: bookkeeping about a failure must not be able to mask it. Carries
     * identifiers and a classification only — no raw body, no signature, no secret.
     */
    private function recordWebhookFailure(
        object $event,
        object $session,
        string $outcome,
        ?\Throwable $e = null,
        ?string $reason = null,
    ): void {
        $detail = [
            'stripe_event_id' => (string) ($event->id ?? 'unknown'),
            'stripe_event_type' => (string) ($event->type ?? 'unknown'),
            'session_reference' => (string) ($session->id ?? ''),
            'payment_reference' => (string) ($session->payment_intent ?? ''),
            'order_id' => $session->metadata->domain_order_id ?? null,
            'classification' => $outcome,
            'retryable' => $outcome === self::OUTCOME_RETRYABLE,
            'exception_class' => $e !== null ? $e::class : null,
            'reason' => $reason ?? ($e !== null ? mb_substr($e->getMessage(), 0, 300) : null),
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'at' => now()->toDateTimeString(),
        ];

        \Illuminate\Support\Facades\Log::error('[StripeWebhook] domain payment processing failed', $detail);

        try {
            $existing = \Illuminate\Support\Facades\Cache::get(self::WEBHOOK_FAILURE_STATE_KEY);

            \Illuminate\Support\Facades\Cache::put(self::WEBHOOK_FAILURE_STATE_KEY, [
                'count' => (int) ($existing['count'] ?? 0) + 1,
                'retryable_count' => (int) ($existing['retryable_count'] ?? 0) + ($detail['retryable'] ? 1 : 0),
                'permanent_count' => (int) ($existing['permanent_count'] ?? 0) + ($detail['retryable'] ? 0 : 1),
                'first_at' => $existing['first_at'] ?? $detail['at'],
                'last_at' => $detail['at'],
                'last' => $detail,
            ], now()->addDays(30));
        } catch (\Throwable $inner) {
            \Illuminate\Support\Facades\Log::error('[StripeWebhook] could not record the failure', [
                'error' => $inner->getMessage(),
            ]);
        }
    }

    /** Records the last SUCCESS separately; it must never erase failure history. */
    private function recordWebhookSuccess(object $event, object $session, array $result): void
    {
        try {
            \Illuminate\Support\Facades\Cache::put(self::WEBHOOK_SUCCESS_STATE_KEY, [
                'stripe_event_id' => (string) ($event->id ?? 'unknown'),
                'stripe_event_type' => (string) ($event->type ?? 'unknown'),
                'order_id' => $result['order_id'] ?? null,
                'action' => $result['action'] ?? null,
                'at' => now()->toDateTimeString(),
            ], now()->addDays(30));
        } catch (\Throwable) {
            // Non-fatal: a missing success marker must never fail a real payment.
        }
    }

    public function handleWebhook(string $payload, string $signature, ?string $sourceIp = null): array
    {
        if (! $this->enabled) {
            return ['handled' => false, 'reason' => 'Stripe not configured'];
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        } catch (\Throwable $liveFailure) {
            // The live secret did not verify. Before rejecting, consider the ONE
            // authorised fixture — fail-closed, and only from loopback.
            $event = $this->verifyAuthorisedFixture($payload, $signature, $sourceIp);
        }

        try {
            if ($event === null) {
                throw new \RuntimeException('unverified');
            }
        } catch (\Throwable $e) {
            // Rejected, NOT acknowledged. A wrong or rotated signing secret would
            // otherwise discard every genuine event silently, with no trace.
            return [
                'handled' => false,
                'outcome' => self::OUTCOME_SIGNATURE_INVALID,
                'error' => 'Invalid webhook signature',
            ];
        }

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;

                // A domain purchase is a one-time payment, not a subscription.
                // Route it before any subscription logic runs -- the checks
                // below assume a plan, which a domain order does not have.
                if ((($session->metadata->order_type ?? null) === 'domain')
                    || (($session->mode ?? null) === 'payment' && ! empty($session->metadata->domain_order_id))) {
                    return $this->handleDomainCheckoutCompleted($event, $session);
                }

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

            // MONEY-1 (2026-08-29): the endpoint subscribes to customer.subscription.created but nothing
            // handled it — a subscription created outside Checkout (Stripe Dashboard by support, API,
            // a migrated customer) never provisioned a plan; the customer paid and stayed on Free.
            // Provision from the subscription's own metadata (workspace_id + plan_id, written by
            // createCheckoutSession and by any operator who follows the same contract).
            case 'customer.subscription.created':
                return $this->handleSubscriptionCreated($event->data->object);

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
            ->whereIn('status', Subscription::ENTITLED_STATUSES) // MONEY-1: a Stripe trial is a live sub
            ->orderByDesc('id')->first();

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
            ->whereIn('status', ['active', 'trialing', 'past_due']) // MONEY-1: portal must work while trialing / past due
            ->whereNotNull('stripe_customer_id')
            ->orderByDesc('id')->first();

        if (! $sub) {
            return ['success' => false, 'error' => 'No Stripe subscription found. Please subscribe to a plan first.'];
        }

        try {
            $stripe = new \Stripe\StripeClient($this->secretKey);
            // USD COMMERCIAL STANDARD: pin an explicit portal configuration.
            // Without one Stripe falls back to the account default, which can list
            // every active price on a product -- including the legacy AED Agency
            // price. Pinning guarantees only USD prices are selectable.
            $portalConfig = env('STRIPE_PORTAL_CONFIG_ID', '');
            $session = $stripe->billingPortal->sessions->create(array_filter([
                'customer'   => $sub->stripe_customer_id,
                'return_url' => config('app.url') . '/app',
                'configuration' => $portalConfig ?: null,
            ]));

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

        // MONEY-1 (2026-08-29): the platform's own 3-day trial (TrialService, provider=trial) has no
        // Stripe subscription, so the card said "Renews 9/1 · 46 / 300 credits" for a trial that
        // EXPIRES on 9/1 with a 50-credit grant. Report the trial as a trial.
        $platformTrial = null;
        try {
            $t = app(TrialService::class)->getTrialStatus($workspaceId);
            if (! empty($t['active'])) $platformTrial = $t;
        } catch (\Throwable) {}
        if ($platformTrial && ! $trialEndsAt) $trialEndsAt = $platformTrial['expires_at'] ?? null;

        return [
            'has_subscription'       => $sub !== null,
            'is_platform_trial'      => $platformTrial !== null,
            'trial_credits'          => $platformTrial ? (int) ($platformTrial['trial_credits'] ?? 0) : null,
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
            'monthly_credit_limit'   => $platformTrial ? (int) ($platformTrial['trial_credits'] ?? 0) : (int) ($sub?->plan?->credit_limit ?? 0),
            'stripe_configured'      => $this->enabled,
        ];
    }

    /**
     * Cancel a subscription.
     */
    public function cancel(int $workspaceId): array
    {
        // MONEY-1 (2026-08-29): a trialing subscription (platform trial or Stripe trial) can be cancelled too.
        // A past-due subscription must be cancellable too — the customer whose card was declined
        // is exactly the one who may want out.
        $sub = Subscription::where('workspace_id', $workspaceId)->whereIn('status', ['active', 'trialing', 'past_due'])->orderByDesc('id')->first();
        if (! $sub) {
            return ['success' => false, 'error' => 'No active subscription'];
        }

        if ($this->enabled && $sub->stripe_subscription_id) {
            $stripe = new \Stripe\StripeClient($this->secretKey);
            $stripe->subscriptions->cancel($sub->stripe_subscription_id);
        }

        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        // Downgrade to free plan — MONEY-1 (2026-08-29): the Stripe cancel above triggers
        // customer.subscription.deleted, whose handler ALSO creates a Free row; observed live as
        // two active Free subscriptions (#140 manual + #141 system). One is enough.
        $this->ensureFreeSubscription((int) $workspaceId, 'manual');

        // T_NOTIF — manual cancel (user-facing, email-required)
        $ownerId = \Illuminate\Support\Facades\DB::table('workspace_users')
            ->where('workspace_id', $workspaceId)
            ->where('role', 'owner')
            ->value('user_id');
        if ($ownerId) {
            try {
                $this->notifications->dispatch(
                    type: \App\Core\Notifications\NotificationTypes::BILLING_SUBSCRIPTION_CANCELLED,
                    userId: (int) $ownerId,
                    title: 'Subscription cancelled',
                    workspaceId: $workspaceId,
                    body: 'Your subscription has been cancelled. Your workspace has been downgraded to the Free plan.',
                    severity: 'warning',
                    actionUrl: '/billing'
                );
            } catch (\Throwable $e) {
                Log::warning('BILLING_SUBSCRIPTION_CANCELLED notification failed (manual cancel)', ['error' => $e->getMessage()]);
            }
        }

        return ['success' => true];
    }

    /**
     * Add agent add-on to subscription.
     */
    public function addAgentAddon(int $workspaceId, string $agentSlug): array
    {
        $sub = Subscription::where('workspace_id', $workspaceId)->whereIn('status', Subscription::ENTITLED_STATUSES)->with('plan')->orderByDesc('id')->first();
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
        // DEC-0027 (2026-08-25): the chatbot add-on is retired. Chatbot is a TIER
        // feature included in every $49+ plan (FeatureGateService::canAccessChatbot).
        // Refuse new add-on purchases so no customer buys an entitlement the gate no
        // longer honours. (Existing add-on item_ids are harmless — access comes from tier.)
        return ['success' => false, 'error' => 'The chatbot is already included in your plan tier ($49 and up) — no separate add-on is needed.'];

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
            $sub->update(['chatbot_addon_item_id' => $localItem, 'chatbot_addon_active' => true]);
            $this->notifyAddonAdded($workspaceId, $userId);
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

            $sub->update(['chatbot_addon_item_id' => $newItem->id, 'chatbot_addon_active' => true]);

            $this->auditLog->log($workspaceId, $userId, 'billing.chatbot_addon_added', 'Subscription', $sub->id, [
                'stripe_subscription_id' => $sub->stripe_subscription_id,
                'item_id'                => $newItem->id,
            ]);

            $this->notifyAddonAdded($workspaceId, $userId);

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
            $sub->update(['chatbot_addon_item_id' => null, 'chatbot_addon_active' => false]);
            $this->notifyAddonRemoved($workspaceId, $userId);
            return ['success' => true, 'dev_mode' => true];
        }

        try {
            $stripe = new \Stripe\StripeClient($this->secretKey);
            $stripe->subscriptionItems->delete($itemId, [
                'proration_behavior' => 'create_prorations',
            ]);
            $sub->update(['chatbot_addon_item_id' => null, 'chatbot_addon_active' => false]);

            $this->auditLog->log($workspaceId, $userId, 'billing.chatbot_addon_removed', 'Subscription', $sub->id, [
                'stripe_subscription_id' => $sub->stripe_subscription_id,
                'item_id'                => $itemId,
            ]);

            $this->notifyAddonRemoved($workspaceId, $userId);

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

        // MONEY-1: a paying customer is no longer on the platform trial — clear the flags the sidebar
        // and TrialService read, or "Growth trial · ends …" survives the upgrade.
        \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->where('is_trial', 1)
            ->update(['is_trial' => 0, 'trial_credits' => 0, 'updated_at' => now()]);

        $this->auditLog->log($wsId, $userId, 'billing.subscription_created', 'Plan', $planId, [
            'plan'           => $plan?->name,
            'stripe_session' => $session->id ?? null,
        ]);

        // SEO-only product mode 2026-05-01: reactivate any wp_site_connections
        // that were billing_suspended by an earlier cancellation. Closes the
        // upgrade-path gap where seo_only → growth via cancel-and-recheckout
        // would leave the WP plugin permanently locked out.
        $this->reactivateBillingForWorkspace((int) $wsId, 'subscription_created_via_checkout');

        // T_NOTIF — subscription created (user-facing notification)
        if ($userId) {
            try {
                $this->notifications->dispatch(
                    type: \App\Core\Notifications\NotificationTypes::BILLING_SUBSCRIPTION_CREATED,
                    userId: (int) $userId,
                    title: 'Subscription activated',
                    workspaceId: (int) $wsId,
                    body: $plan ? "Your {$plan->name} plan is now active." : 'Your plan is now active.',
                    severity: 'success',
                    actionUrl: '/billing'
                );
            } catch (\Throwable $e) {
                Log::warning('BILLING_SUBSCRIPTION_CREATED notification failed', ['error' => $e->getMessage()]);
            }
        }

        return ['handled' => true, 'action' => 'subscription_created', 'plan' => $plan?->slug];
    }

    /**
     * Provision a plan for a Stripe subscription that did not come through Checkout.
     * Idempotent on stripe_subscription_id; a subscription already provisioned by
     * checkout.session.completed (same id) is left alone.
     */
    private function handleSubscriptionCreated(object $subscription): array
    {
        $wsId   = (int) ($subscription->metadata->workspace_id ?? 0);
        $planId = (int) ($subscription->metadata->plan_id ?? 0);
        if (! $wsId || ! $planId) {
            // No contract metadata: nothing to provision. Acknowledged, not an error.
            return ['handled' => false, 'reason' => 'no_workspace_metadata', 'type' => 'customer.subscription.created'];
        }
        if (Subscription::where('stripe_subscription_id', $subscription->id)->exists()) {
            return ['handled' => true, 'action' => 'already_provisioned', 'type' => 'customer.subscription.created'];
        }
        $plan = Plan::find($planId);
        if (! $plan) return ['handled' => false, 'reason' => 'unknown_plan'];

        $status = match ($subscription->status ?? 'active') {
            'trialing' => 'trialing', 'past_due' => 'past_due', 'canceled' => 'cancelled', default => 'active',
        };
        $provisioned = false;
        \Illuminate\Support\Facades\DB::transaction(function () use ($wsId, $planId, $plan, $subscription, $status, &$provisioned) {
            $inserted = \Illuminate\Support\Facades\DB::table('subscriptions')->insertOrIgnore([
                'workspace_id'             => $wsId,
                'plan_id'                  => $planId,
                'provider'                 => 'stripe',
                'status'                   => $status,
                'starts_at'                => now(),
                'ends_at'                  => ! empty($subscription->trial_end) && $status === 'trialing' ? date('Y-m-d H:i:s', (int) $subscription->trial_end) : null,
                'provider_subscription_id' => 'stripe_sub:' . $subscription->id,   // idempotency anchor
                'stripe_subscription_id'   => $subscription->id,
                'stripe_customer_id'       => $subscription->customer ?? null,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);
            if ($inserted === 0) return;
            $provisioned = true;
            // NULL-safe (same trap as v5.5.5): the platform trial row has stripe_subscription_id NULL and
            // `NULL != 'sub_x'` is NULL in MySQL — the first live run left the trial row 'trialing'.
            Subscription::where('workspace_id', $wsId)
                ->whereIn('status', ['active', 'trialing'])
                ->where(function ($q) use ($subscription) {
                    $q->whereNull('stripe_subscription_id')->orWhere('stripe_subscription_id', '!=', $subscription->id);
                })
                ->update(['status' => 'superseded']);
            Credit::where('workspace_id', $wsId)->lockForUpdate()->first()
                ? Credit::where('workspace_id', $wsId)->update(['balance' => $plan->credit_limit, 'reserved_balance' => 0, 'updated_at' => now()])
                : Credit::create(['workspace_id' => $wsId, 'balance' => $plan->credit_limit, 'reserved_balance' => 0]);
        });
        if (! $provisioned) return ['handled' => true, 'action' => 'already_provisioned', 'type' => 'customer.subscription.created'];

        // The platform trial, if still running, is over: the customer is now a paying subscriber.
        \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->where('is_trial', 1)
            ->update(['is_trial' => 0, 'trial_credits' => 0, 'updated_at' => now()]);

        $this->auditLog->log($wsId, null, 'billing.subscription_created', 'Plan', $planId, [
            'plan' => $plan->name, 'stripe_subscription' => $subscription->id, 'via' => 'customer.subscription.created',
        ]);
        $this->reactivateBillingForWorkspace($wsId, 'subscription_created_via_stripe');
        $ownerId = \Illuminate\Support\Facades\DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->value('user_id');
        if ($ownerId) {
            try {
                $this->notifications->dispatch(
                    type: \App\Core\Notifications\NotificationTypes::BILLING_SUBSCRIPTION_CREATED,
                    userId: (int) $ownerId, title: 'Subscription activated', workspaceId: $wsId,
                    body: "Your {$plan->name} plan is now active.", severity: 'success', actionUrl: '/billing'
                );
            } catch (\Throwable $e) {
                Log::warning('BILLING_SUBSCRIPTION_CREATED notification failed (subscription.created)', ['error' => $e->getMessage()]);
            }
        }
        return ['handled' => true, 'action' => 'subscription_created', 'plan' => $plan->slug, 'status' => $status];
    }

    /**
     * MONEY-1 (2026-08-29): which subscription does this invoice belong to?
     *
     * The webhook endpoint runs on Stripe API 2026-03-25 (dahlia). From 2025-03-31.basil onwards
     * `invoice.subscription` no longer exists — it moved to `invoice.parent.subscription_details.subscription`.
     * Both handlers below read the old field, so EVERY invoice.paid (renewal credit refresh) and
     * EVERY invoice.payment_failed (past_due) was dropped with "subscription_not_found" — observed live
     * twice on 2026-08-29 (10:57:07, 11:00:22). Renewals never refreshed credits; failed payments
     * never suspended anything.
     */
    private function invoiceSubscriptionId(object $invoice): ?string
    {
        $id = $invoice->subscription ?? null;
        if (is_object($id)) $id = $id->id ?? null;
        if (! $id) $id = $invoice->parent->subscription_details->subscription ?? null;
        if (is_object($id)) $id = $id->id ?? null;
        if (! $id) {
            foreach (($invoice->lines->data ?? []) as $line) {
                $lid = $line->subscription ?? ($line->parent->subscription_item_details->subscription ?? null);
                if ($lid) { $id = is_object($lid) ? ($lid->id ?? null) : $lid; break; }
            }
        }
        return $id ? (string) $id : null;
    }

    private function handleInvoicePaid(object $invoice): array
    {
        // Monthly credit refresh on renewal
        $invoiceSubId = $this->invoiceSubscriptionId($invoice);
        $sub = Subscription::where('stripe_subscription_id', $invoiceSubId ?? '')->first();
        if (! $sub && $invoiceSubId && $this->enabled) {
            // MONEY-1 (2026-08-29): Stripe delivers invoice.paid BEFORE customer.subscription.created
            // (observed live: 10:57:07 vs 10:57:08). Provision from the subscription itself rather
            // than dropping the first paid invoice on the floor.
            try {
                $stripeSub = (new \Stripe\StripeClient($this->secretKey))->subscriptions->retrieve($invoiceSubId);
                $created = $this->handleSubscriptionCreated($stripeSub);
                if (! empty($created['handled'])) {
                    $sub = Subscription::where('stripe_subscription_id', $invoiceSubId)->first();
                }
            } catch (\Throwable $e) {
                Log::warning('handleInvoicePaid: could not provision from subscription', ['error' => $e->getMessage()]);
            }
        }
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
        $sub = Subscription::where('stripe_subscription_id', $this->invoiceSubscriptionId($invoice) ?? '')->first();
        if ($sub) {
            $sub->update(['status' => 'past_due']);
            Log::warning("Payment failed for workspace {$sub->workspace_id}");

            // T_NOTIF — payment failed (user-facing, email-required)
            $ownerId = \Illuminate\Support\Facades\DB::table('workspace_users')
                ->where('workspace_id', $sub->workspace_id)
                ->where('role', 'owner')
                ->value('user_id');
            if ($ownerId) {
                try {
                    $this->notifications->dispatch(
                        type: \App\Core\Notifications\NotificationTypes::BILLING_PAYMENT_FAILED,
                        userId: (int) $ownerId,
                        title: 'Payment failed',
                        workspaceId: (int) $sub->workspace_id,
                        body: 'A payment for your subscription failed. Please update your payment method to avoid service interruption.',
                        severity: 'error',
                        actionUrl: '/billing'
                    );
                } catch (\Throwable $e) {
                    Log::warning('BILLING_PAYMENT_FAILED notification failed', ['error' => $e->getMessage()]);
                }
            }
        }
        return ['handled' => true, 'action' => 'subscription_past_due'];
    }

    /** Exactly one active Free subscription after a downgrade, whichever path got there first. */
    private function ensureFreeSubscription(int $workspaceId, string $provider): void
    {
        $freePlan = Plan::where('slug', 'free')->first();
        if (! $freePlan) return;
        $exists = Subscription::where('workspace_id', $workspaceId)->where('status', 'active')->where('plan_id', $freePlan->id)->exists();
        if ($exists) return;
        Subscription::create([
            'workspace_id' => $workspaceId,
            'plan_id'      => $freePlan->id,
            'provider'     => $provider,
            'status'       => 'active',
            'starts_at'    => now(),
        ]);
    }

    private function handleSubscriptionCancelled(object $subscription): array
    {
        $sub = Subscription::where('stripe_subscription_id', $subscription->id)->first();
        if ($sub) {
            $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            // Downgrade to free plan (idempotent with cancel())
            $this->ensureFreeSubscription((int) $sub->workspace_id, 'system');

            // Zero credits
            Credit::where('workspace_id', $sub->workspace_id)
                ->update(['balance' => 0, 'reserved_balance' => 0]);

            // P0 hardening 2026-05-01: suspend WP site connections (reversible).
            $this->suspendBillingForWorkspace($sub->workspace_id, 'subscription_cancelled');

            // T_NOTIF — subscription cancelled (user-facing, email-required)
            $ownerId = \Illuminate\Support\Facades\DB::table('workspace_users')
                ->where('workspace_id', $sub->workspace_id)
                ->where('role', 'owner')
                ->value('user_id');
            if ($ownerId) {
                try {
                    $this->notifications->dispatch(
                        type: \App\Core\Notifications\NotificationTypes::BILLING_SUBSCRIPTION_CANCELLED,
                        userId: (int) $ownerId,
                        title: 'Subscription cancelled',
                        workspaceId: (int) $sub->workspace_id,
                        body: 'Your subscription has been cancelled. Your workspace has been downgraded to the Free plan.',
                        severity: 'warning',
                        actionUrl: '/billing'
                    );
                } catch (\Throwable $e) {
                    Log::warning('BILLING_SUBSCRIPTION_CANCELLED notification failed', ['error' => $e->getMessage()]);
                }
            }
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
                $sub->update(['chatbot_addon_item_id' => $foundAddonItemId, 'chatbot_addon_active' => (bool) $foundAddonItemId]);
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
            // MONEY-1 (2026-08-29): this fired "Your subscription has been upgraded." on EVERY
            // transition — observed live right after a declined renewal (active → past_due), one
            // line under "Payment failed". Only a transition INTO an entitled state is an upgrade.
            if ($previousStatus !== $newStatus && in_array($newStatus, ['active', 'trialing'], true)) {
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
        // WP CONNECTOR COMPAT (2026-08-04) — connector cleanup is OPTIONAL work
        // hanging off a billing event. The catch below already stopped it from
        // failing the webhook, but it was swallowing a "table doesn't exist"
        // every single time and calling it non-fatal. Returning early makes the
        // absence explicit and structured, and stops the exception churn.
        // Billing itself is unaffected either way: the caller has already done
        // the subscription work before reaching here.
        if (! \App\Core\Platform\Connector\WpConnectorSchema::availableFor('billing.suspend_connections')) {
            return;
        }

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
        // WP CONNECTOR COMPAT (2026-08-04) — see suspendBillingForWorkspace.
        // No connector row is invented here; an absent feature reactivates
        // nothing, which is the truthful outcome.
        if (! \App\Core\Platform\Connector\WpConnectorSchema::availableFor('billing.reactivate_connections')) {
            return;
        }

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

        // T_NOTIF — dev-mode plan activation (no billing event, info severity)
        try {
            $this->notifications->dispatch(
                type: \App\Core\Notifications\NotificationTypes::SYSTEM_DEV_PLAN_ACTIVATED,
                userId: $userId,
                title: 'Plan activated (dev mode)',
                workspaceId: $wsId,
                body: $plan ? "Your {$plan->name} plan is now active (dev mode — no payment processed)." : 'Your plan is now active (dev mode).',
                severity: 'info',
                actionUrl: '/billing'
            );
        } catch (\Throwable $e) {
            Log::warning('SYSTEM_DEV_PLAN_ACTIVATED notification failed (devActivate)', ['error' => $e->getMessage()]);
        }

        return ['checkout_url' => null, 'dev_mode' => true, 'activated' => true, 'plan' => $plan?->name];
    }

    /**
     * Helper — fire BILLING_CHATBOT_ADDON_ADDED. Idempotent in failure (try/catch).
     */
    private function notifyAddonAdded(int $workspaceId, int $userId): void
    {
        try {
            $this->notifications->dispatch(
                type: \App\Core\Notifications\NotificationTypes::BILLING_CHATBOT_ADDON_ADDED,
                userId: $userId,
                title: 'Chatbot add-on activated',
                workspaceId: $workspaceId,
                body: 'Your chatbot add-on is now active. The widget can be enabled on your published sites.',
                severity: 'success',
                actionUrl: '/admin/chatbot'
            );
        } catch (\Throwable $e) {
            Log::warning('BILLING_CHATBOT_ADDON_ADDED notification failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Helper — fire BILLING_CHATBOT_ADDON_REMOVED.
     */
    private function notifyAddonRemoved(int $workspaceId, int $userId): void
    {
        try {
            $this->notifications->dispatch(
                type: \App\Core\Notifications\NotificationTypes::BILLING_CHATBOT_ADDON_REMOVED,
                userId: $userId,
                title: 'Chatbot add-on removed',
                workspaceId: $workspaceId,
                body: 'Your chatbot add-on has been cancelled. The widget will stop appearing on your sites.',
                severity: 'info',
                actionUrl: '/admin/chatbot'
            );
        } catch (\Throwable $e) {
            Log::warning('BILLING_CHATBOT_ADDON_REMOVED notification failed', ['error' => $e->getMessage()]);
        }
    }
}
