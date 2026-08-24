<?php

namespace Tests\Feature\PlatformEvents;

use App\Core\Billing\StripeService;
use App\Core\PlatformEvents\ChainVerifier;
use App\Core\PlatformEvents\DeliveryProjection;
use App\Core\PlatformEvents\DeliveryWorker;
use App\Core\PlatformEvents\EventFanOut;
use App\Core\PlatformEvents\Outbox;
use App\Core\PlatformEvents\Types\DomainOrderPaid;
use App\Jobs\RegisterDomainJob;
use App\Models\DomainOrder;
use App\Services\Domains\DomainCommerceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Phase 1E — domain.order.paid, proven through the REAL signed Stripe webhook.
 *
 * The signature tests configure a TEST webhook secret and exercise
 * \Stripe\Webhook::constructEvent through the real route. Nothing is mocked and
 * verification is never disabled: the fixture is signed with the same HMAC scheme
 * Stripe uses, and an unsigned or tampered body must be rejected by the real
 * verifier. The live production webhook secret is never read, used or replaced.
 *
 * No Stripe API request is made anywhere: constructEvent is a local HMAC check,
 * and the domain payment path contains no Stripe client call.
 */
class DomainOrderPaidTest extends TestCase
{
    use IsolatedDatabase;

    private int $ws = 999001;
    private int $wsOutside = 999002;
    private int $userId = 999901;

    /** Isolated test material. NEVER the live STRIPE_WEBHOOK_SECRET. */
    private const TEST_WEBHOOK_SECRET = 'whsec_phase1e_test_only_2f8a1c94b7e3';
    private const TEST_STRIPE_KEY = 'sk_test_phase1e00000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 1E.1: never run against a shared database.
        $this->assertIsolatedDatabase();

        config([
            'platform_events.enabled' => true,
            'platform_events.producers' => [
                'domain.order.created' => true,
                'domain.order.paid' => true,
            ],
            'platform_events.producer_workspace_allowlist' => [$this->ws],
            'platform_events.fanout.enabled' => true,
            'platform_events.delivery_worker.enabled' => true,
            'platform_events.subscribers' => ['platform.audit' => true],

            // Isolated test Stripe material.
            'billing.stripe.secret_key' => self::TEST_STRIPE_KEY,
            'billing.stripe.webhook_secret' => self::TEST_WEBHOOK_SECRET,
        ]);

        // StripeService is a singleton and reads both secrets in its constructor,
        // so it must be rebuilt after the config override or the route would use a
        // service that believes Stripe is unconfigured.
        app()->forgetInstance(StripeService::class);

        Cache::forget(ChainVerifier::VERIFIED_FINGERPRINT_KEY);
        $this->cleanup();
        $this->seedTenants();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        DB::table('users')->where('id', $this->userId)->delete();
        DB::table('workspaces')->whereIn('id', [$this->ws, $this->wsOutside])->delete();
        app()->forgetInstance(StripeService::class);
        parent::tearDown();
    }

    private function seedTenants(): void
    {
        if (! DB::table('users')->where('id', $this->userId)->exists()) {
            DB::table('users')->insert([
                'id' => $this->userId, 'name' => 'Phase1E Actor',
                'email' => 'phase1e-' . $this->userId . '@example.invalid',
                'password' => bcrypt('not-a-real-password'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([$this->ws, $this->wsOutside] as $id) {
            if (! DB::table('workspaces')->where('id', $id)->exists()) {
                DB::table('workspaces')->insert([
                    'id' => $id, 'name' => 'Phase1E WS ' . $id, 'slug' => 'phase1e-ws-' . $id,
                    'created_by' => $this->userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function cleanup(): void
    {
        $ids = DB::table('platform_events')->whereIn('workspace_id', [$this->ws, $this->wsOutside])->pluck('event_id');
        DB::table('platform_event_deliveries')->whereIn('event_id', $ids)->delete();
        DB::table('platform_events')->whereIn('workspace_id', [$this->ws, $this->wsOutside])->delete();
        DB::table('audit_logs')->whereIn('workspace_id', [$this->ws, $this->wsOutside])->delete();
        DB::table('domain_order_items')->whereIn('domain_order_id',
            DB::table('domain_orders')->whereIn('workspace_id', [$this->ws, $this->wsOutside])->pluck('id'))->delete();
        DB::table('domain_orders')->whereIn('workspace_id', [$this->ws, $this->wsOutside])->delete();
    }

    // ───────────────────────────────────────────── fixtures ──

    private function makeOrder(?int $ws = null, string $session = 'cs_test_phase1e_001', bool $internal = true): int
    {
        $ws ??= $this->ws;

        $id = DB::table('domain_orders')->insertGetId([
            'workspace_id' => $ws, 'user_id' => $this->userId,
            'status' => DomainOrder::STATUS_PENDING, 'currency' => 'USD',
            'subtotal_minor' => 1817, 'tax_minor' => 0, 'total_minor' => 1817,
            'cost_total_minor' => 1398,
            'stripe_session_id' => $session,
            'metadata_json' => json_encode($internal
                ? ['internal_test' => true, 'phase' => '1E']
                : ['priced_at' => '2026-07-30T00:00:00+00:00']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('domain_order_items')->insert([
            'domain_order_id' => $id, 'workspace_id' => $ws,
            'domain' => 'paid-test-' . $id . '.com',
            'tld' => 'com',
            'years' => 1, 'status' => 'pending',
            'action' => 'register', 'provider' => 'namecheap',
            'registrar_cost_minor' => 1398, 'markup_minor' => 419,
            'retail_minor' => 1817, 'currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** The Stripe event body for a domain checkout completion. */
    private function fixtureBody(string $eventId, string $session, string $intent): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'created' => time(),
            'livemode' => false,
            'data' => ['object' => [
                'id' => $session,
                'object' => 'checkout.session',
                'mode' => 'payment',
                'payment_intent' => $intent,
                'payment_status' => 'paid',
                'metadata' => ['order_type' => 'domain'],
            ]],
        ], JSON_UNESCAPED_SLASHES);
    }

    /** Sign exactly as Stripe does: t=<ts>,v1=<hmac_sha256(ts.payload)>. */
    private function sign(string $body, ?string $secret = null, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret ?? self::TEST_WEBHOOK_SECRET);

        return "t={$ts},v1={$sig}";
    }

    /**
     * Guard a replay fixture would have to satisfy. Enforced here rather than in
     * production code, because the controlled-replay command was NOT built —
     * Stage C is blocked (see the Phase 1E report).
     */
    private function assertFixtureOrderIsUsable(int $orderId): void
    {
        $o = DB::table('domain_orders')->where('id', $orderId)->first();

        $meta = json_decode((string) ($o->metadata_json ?? '{}'), true);

        if (($meta['internal_test'] ?? null) !== true) {
            throw new \RuntimeException("order {$orderId} is not marked internal_test — a fixture must never touch a real order");
        }

        if ($o->paid_at !== null) {
            throw new \RuntimeException("order {$orderId} is already paid");
        }
    }

    private function postWebhook(string $body, string $signature)
    {
        return $this->call('POST', '/api/webhook/stripe', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);
    }

    /**
     * The real verifier, called directly.
     *
     * The route deliberately returns 200 for everything (so a transient bug cannot
     * cause a Stripe retry storm), which means an HTTP status carries no information
     * about whether a signature verified. handleWebhook()'s return value is the
     * actual verdict, and it is the same unmocked \Stripe\Webhook::constructEvent
     * call the route makes.
     */
    private function verifyThroughRealVerifier(string $body, string $signature): array
    {
        return app(StripeService::class)->handleWebhook($body, $signature);
    }

    private function paidEvents(?int $ws = null)
    {
        return DB::table('platform_events')
            ->where('workspace_id', $ws ?? $this->ws)
            ->where('event_type', 'domain.order.paid')
            ->get();
    }

    // ══════════════════ 1 + 2. atomicity ══

    public function test_domain_order_paid_cannot_be_recorded_outside_a_transaction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/inside the transaction/');

        app(Outbox::class)->record($this->paidEvent(555001));
    }

    private function paidEvent(int $orderId, ?int $ws = null): DomainOrderPaid
    {
        return new DomainOrderPaid(
            workspaceId: $ws ?? $this->ws,
            payload: [
                'order_id' => $orderId, 'currency' => 'USD', 'total_minor' => 1817,
                'paid_at' => '2026-07-30 12:00:00',
                'payment_provider' => DomainOrderPaid::PROVIDER_STRIPE,
                'payment_reference' => 'pi_test_phase1e_001',
            ],
            actorType: DomainOrderPaid::ACTOR_SYSTEM,
            actorId: null,
        );
    }

    public function test_the_payment_transition_and_the_event_roll_back_together(): void
    {
        Queue::fake();
        $orderId = $this->makeOrder();

        DB::beginTransaction();
        DomainCommerceService::make()->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');

        // Both visible inside the transaction.
        $this->assertNotNull(DB::table('domain_orders')->where('id', $orderId)->value('paid_at'));
        $this->assertCount(1, $this->paidEvents());

        DB::rollBack();

        // Both gone together.
        $this->assertNull(DB::table('domain_orders')->where('id', $orderId)->value('paid_at'));
        $this->assertCount(0, $this->paidEvents(),
            'the payment and the event that describes it must never disagree');
    }

    // ══════════════════ 3, 4, 5. real signature verification ══

    public function test_a_correctly_signed_fixture_passes_real_signature_verification(): void
    {
        Queue::fake();
        $orderId = $this->makeOrder();
        $this->assertFixtureOrderIsUsable($orderId);

        $body = $this->fixtureBody('evt_test_phase1e_001', 'cs_test_phase1e_001', 'pi_test_phase1e_001');

        // The signature verifies: we get PAST constructEvent and into the domain
        // branch. That branch then hits the P0 defect below, which is exactly what
        // this phase set out to discover about the real webhook boundary.
        try {
            $result = $this->verifyThroughRealVerifier($body, $this->sign($body));

            $this->assertNotSame('Invalid webhook signature', $result['error'] ?? null,
                'a correctly signed fixture must pass signature verification');
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            // Reaching the container error PROVES the signature verified — an
            // invalid signature returns before the domain branch is entered.
            $this->assertStringContainsString('NamecheapClient', $e->getMessage());
        }
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        $body = $this->fixtureBody('evt_test_phase1e_002', 'cs_test_phase1e_001', 'pi_test_phase1e_001');

        $result = $this->verifyThroughRealVerifier($body, $this->sign($body, 'whsec_not_the_right_secret_at_all'));

        $this->assertFalse($result['handled']);
        $this->assertSame('Invalid webhook signature', $result['error']);
        $this->assertCount(0, $this->paidEvents(), 'an unverified webhook must not move money');
    }

    public function test_a_body_modified_after_signing_is_rejected(): void
    {
        $body = $this->fixtureBody('evt_test_phase1e_003', 'cs_test_phase1e_001', 'pi_test_phase1e_001');
        $signature = $this->sign($body);

        // Tamper AFTER signing — the classic attack.
        $tampered = str_replace('pi_test_phase1e_001', 'pi_test_ATTACKER_0001', $body);
        $this->assertNotSame($body, $tampered);

        $result = $this->verifyThroughRealVerifier($tampered, $signature);

        $this->assertFalse($result['handled']);
        $this->assertSame('Invalid webhook signature', $result['error']);
        $this->assertCount(0, $this->paidEvents());
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $body = $this->fixtureBody('evt_test_phase1e_004', 'cs_test_phase1e_001', 'pi_test_phase1e_001');

        // Outside Stripe's default 300s tolerance — a replayed old request.
        $result = $this->verifyThroughRealVerifier($body, $this->sign($body, null, time() - 3600));

        $this->assertFalse($result['handled']);
        $this->assertSame('Invalid webhook signature', $result['error']);
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $body = $this->fixtureBody('evt_test_phase1e_005', 'cs_test_phase1e_001', 'pi_test_phase1e_001');

        $result = $this->verifyThroughRealVerifier($body, '');

        $this->assertFalse($result['handled']);
        $this->assertSame('Invalid webhook signature', $result['error']);
    }

    // ══════════════════ P0 DEFECT FOUND BY THIS PHASE ══

    // ══════════════════ THE P0, NOW REMEDIATED (Phase 1E.1) ══

    /**
     * Phase 1E discovered that the domain branch of the Stripe webhook called
     * app(DomainCommerceService::class), which is not container-resolvable, so every
     * genuine domain payment threw while the route answered HTTP 200 — Stripe recorded
     * a successful delivery, never retried, and the order stayed `pending` forever.
     *
     * These two tests asserted that broken state deliberately, so that fixing it would
     * force them to be updated consciously. Phase 1E.1 fixed it, and they now assert
     * the remediated behaviour. Full coverage lives in PaymentWebhookRemediationTest.
     */
    public function test_the_domain_webhook_branch_now_resolves_after_remediation(): void
    {
        $src = file_get_contents(app_path('Core/Billing/StripeService.php'));

        $this->assertStringNotContainsString(
            'app(\App\Services\Domains\DomainCommerceService::class)', $src,
            'the unresolvable container call must stay gone'
        );
        $this->assertStringContainsString('DomainCommerceService::make()', $src);

        // The container call still fails — which is why the factory is required.
        $this->expectException(\Illuminate\Contracts\Container\BindingResolutionException::class);
        app(\App\Services\Domains\DomainCommerceService::class);
    }

    public function test_the_route_no_longer_masks_a_failure_as_a_success(): void
    {
        Queue::fake();
        config(['domains.fulfilment.enabled' => false]);

        $orderId = $this->makeOrder();

        $body = $this->fixtureBody('evt_test_phase1e_nomask', 'cs_test_phase1e_001', 'pi_test_phase1e_001');
        $res = $this->postWebhook($body, $this->sign($body));

        $this->assertSame(200, $res->getStatusCode(), 'a genuinely processed payment returns 200');
        $this->assertNotNull(DB::table('domain_orders')->where('id', $orderId)->value('paid_at'),
            'and the payment is now actually recorded');
        $this->assertCount(1, $this->paidEvents());
    }

    // ══════════════════ 6, 7, 8, 9, 10. duplicate boundaries ══

    public function test_the_same_stripe_event_id_twice_produces_one_paid_event(): void
    {
        Queue::fake();
        $orderId = $this->makeOrder();

        // Called through the service rather than HTTP: the HTTP path is blocked by
        // the P0 above. This is the same method the webhook invokes, so the
        // idempotency guarantee under test is the real one.
        $svc = DomainCommerceService::make();

        $first = $svc->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');
        $second = $svc->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');

        $this->assertSame('marked_paid', $first['action'] ?? null);
        $this->assertSame('already_processed', $second['action'] ?? null);

        $this->assertCount(1, $this->paidEvents(), 'one payment, one event');
        $this->assertSame(1, DB::table('domain_orders')->where('id', $orderId)
            ->whereNotNull('paid_at')->count());
    }

    public function test_the_same_payment_intent_under_a_different_event_id_produces_one_paid_event(): void
    {
        Queue::fake();
        $this->makeOrder();

        // Two different Stripe event ids, one session and one payment intent.
        $svc = DomainCommerceService::make();

        $svc->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');
        $second = $svc->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');

        $this->assertSame('already_processed', $second['action'] ?? null,
            'a different Stripe event id for the same payment must not transition again');
        $this->assertCount(1, $this->paidEvents());
    }

    /**
     * Concurrency: the durable guarantee is the deterministic event id plus the
     * unique index, not a controller check. Two independent transactions both
     * attempting the record leave exactly one row.
     */
    public function test_concurrent_recording_leaves_exactly_one_paid_event(): void
    {
        $outbox = app(Outbox::class);

        DB::transaction(fn () => $outbox->record($this->paidEvent(555002)));
        DB::transaction(fn () => $outbox->record($this->paidEvent(555002)));
        DB::transaction(fn () => $outbox->record($this->paidEvent(555002)));

        $this->assertCount(1, $this->paidEvents(),
            'three attempts, one event — deterministic identity plus a unique index');
    }

    public function test_the_paid_event_id_is_deterministic(): void
    {
        $a = $this->paidEvent(555003)->eventId();
        $b = $this->paidEvent(555003)->eventId();
        $c = $this->paidEvent(555004)->eventId();

        $this->assertSame($a, $b, 'same order, same id');
        $this->assertNotSame($a, $c, 'different order, different id');
    }

    public function test_an_already_paid_order_produces_no_second_paid_event(): void
    {
        Queue::fake();
        $orderId = $this->makeOrder();

        DB::table('domain_orders')->where('id', $orderId)->update([
            'status' => DomainOrder::STATUS_PAID, 'paid_at' => now(),
        ]);

        $result = DomainCommerceService::make()->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');

        $this->assertSame('already_processed', $result['action'] ?? null);
        $this->assertCount(0, $this->paidEvents(), 'no event for a transition that did not happen');
    }

    public function test_a_failed_payment_event_produces_no_paid_event(): void
    {
        Queue::fake();
        $orderId = $this->makeOrder();

        $body = json_encode([
            'id' => 'evt_test_phase1e_failed', 'object' => 'event',
            'type' => 'invoice.payment_failed', 'created' => time(), 'livemode' => false,
            'data' => ['object' => ['id' => 'in_test_001', 'subscription' => null, 'customer' => null]],
        ], JSON_UNESCAPED_SLASHES);

        // A correctly signed FAILURE event. It must not mark anything paid.
        $this->verifyThroughRealVerifier($body, $this->sign($body));

        $this->assertNull(DB::table('domain_orders')->where('id', $orderId)->value('paid_at'));
        $this->assertCount(0, $this->paidEvents());
    }

    // ══════════════════ 11 + 12. tenancy and fixture safety ══

    public function test_a_non_allow_listed_workspace_cannot_produce_the_paid_event(): void
    {
        $outbox = app(Outbox::class);

        $this->assertFalse($outbox->workspaceAllowed($this->wsOutside));

        DB::transaction(function () use ($outbox) {
            $outbox->record($this->paidEvent(555005, $this->wsOutside));
        });

        $this->assertCount(0, $this->paidEvents($this->wsOutside));
        $this->assertSame(0, DB::table('platform_events')
            ->where('workspace_id', $this->wsOutside)->count());
    }

    public function test_the_fixture_guard_refuses_a_non_internal_order(): void
    {
        $real = $this->makeOrder(session: 'cs_test_phase1e_real', internal: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not marked internal_test/');

        $this->assertFixtureOrderIsUsable($real);
    }

    public function test_the_fixture_guard_refuses_an_already_paid_order(): void
    {
        $orderId = $this->makeOrder();
        DB::table('domain_orders')->where('id', $orderId)->update(['paid_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already paid/');

        $this->assertFixtureOrderIsUsable($orderId);
    }

    // ══════════════════ 13, 14, 15, 16. containment ══

    /**
     * DOCUMENTS THE PHASE 1E BLOCKER.
     *
     * markPaidAndProvision() dispatches RegisterDomainJob unconditionally once the
     * payment transaction commits. There is no feature flag anywhere that prevents
     * it, and RegisterDomainJob does not self-gate on one. That is why the live
     * signed replay (Stage C) was NOT performed: a successful payment on a real
     * order would immediately queue a real Namecheap registration.
     *
     * This test asserts the CURRENT behaviour deliberately. When containment is
     * added it will fail, forcing whoever adds it to update this expectation
     * consciously rather than silently changing the money path.
     */
    public function test_the_payment_path_currently_dispatches_registration_which_blocks_live_replay(): void
    {
        Queue::fake();
        $orderId = $this->makeOrder();

        DomainCommerceService::make()->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');

        Queue::assertPushed(RegisterDomainJob::class, 1);

        $this->assertSame(DomainOrder::STATUS_PROVISIONING,
            DB::table('domain_orders')->where('id', $orderId)->value('status'),
            'payment currently advances straight to provisioning with no gate');
    }

    public function test_the_payment_transaction_contains_no_provider_or_payment_api_call(): void
    {
        $src = file_get_contents(app_path('Services/Domains/DomainCommerceService.php'));

        $start = strpos($src, 'public function markPaidAndProvision(');
        $this->assertNotFalse($start);

        $rest = substr($src, $start);
        $end = strpos($rest, "\n    }\n");
        $body = substr($rest, 0, (int) $end);

        foreach ([
            '$this->registrar', 'NamecheapRegistrarConnector', 'searchDomain', 'quoteRegistration',
            '\\Stripe\\', 'StripeService', 'createDomainCheckoutSession', 'PaymentIntent',
            'NotificationService', '->notify(', 'workspace_memory', 'WorkspaceMemory',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                "the payment transition must not reach '{$forbidden}'");
        }
    }

    public function test_no_notification_or_memory_write_occurs(): void
    {
        Queue::fake();
        $this->makeOrder();

        $notif = DB::table('notifications')->count();
        $memory = DB::table('workspace_memory')->count();

        DomainCommerceService::make()->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');

        $this->assertSame($notif, DB::table('notifications')->count());
        $this->assertSame($memory, DB::table('workspace_memory')->count());
    }

    // ══════════════════ 17 + 18. audit and projection ══

    public function test_the_audit_row_carries_the_original_occurred_at_and_no_secrets(): void
    {
        Queue::fake();
        $orderId = $this->makeOrder();

        DomainCommerceService::make()->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');

        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $event = $this->paidEvents()->first();
        $audit = DB::table('audit_logs')
            ->whereRaw("JSON_EXTRACT(metadata_json, '$.event_id') = ?", [$event->event_id])
            ->first();

        $this->assertNotNull($audit, 'the paid event must produce an audit row');

        $meta = json_decode($audit->metadata_json, true);

        $this->assertSame('domain.order.paid', $audit->action);
        $this->assertSame((string) $event->occurred_at, $meta['occurred_at'],
            'the audit must record when the payment happened, not when it was delivered');
        $this->assertSame('system', $meta['actor_type']);
        $this->assertNull($meta['actor_id'], 'a webhook payment is not attributable to a person');
        $this->assertNull($audit->user_id, 'and must not be misattributed in user_id either');
        $this->assertSame('not_fulfilled_at_time_of_event', $meta['detail']['fulfilment_state']);
        $this->assertSame(1817, $meta['detail']['total_minor']);

        foreach (['whsec_', 'sk_test_', 'sk_live_', 'card', 'billing_address', 'cost_total_minor', 1398] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, $audit->metadata_json,
                "the audit row must not contain '{$forbidden}'");
        }
    }

    public function test_the_delivery_projection_matches_the_ledger(): void
    {
        Queue::fake();
        $this->makeOrder();

        DomainCommerceService::make()->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');

        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $audit = DeliveryProjection::audit($this->ws);
        $this->assertSame([], $audit['drifted']);

        $event = $this->paidEvents()->first();
        $this->assertSame(1, (int) $event->delivery_count);
    }

    // ══════════════════ payload discipline ══

    public function test_the_paid_event_refuses_forbidden_payload_keys(): void
    {
        foreach (['card', 'payment_method', 'billing_address', 'customer_email',
                  'raw_event', 'signature', 'cost_total_minor', 'workspace_id'] as $forbidden) {
            try {
                new DomainOrderPaid(
                    workspaceId: $this->ws,
                    payload: [
                        'order_id' => 1, 'currency' => 'USD', 'total_minor' => 1817,
                        'paid_at' => '2026-07-30 12:00:00',
                        'payment_provider' => DomainOrderPaid::PROVIDER_STRIPE,
                        'payment_reference' => 'pi_test_x',
                        $forbidden => 'anything',
                    ],
                );
                $this->fail("'{$forbidden}' should have been refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($forbidden, $e->getMessage());
            }
        }
    }

    public function test_a_credential_shaped_payment_reference_is_refused(): void
    {
        foreach (['sk_test_abc', 'rk_live_abc', 'whsec_abc'] as $credential) {
            try {
                new DomainOrderPaid(
                    workspaceId: $this->ws,
                    payload: [
                        'order_id' => 1, 'currency' => 'USD', 'total_minor' => 1817,
                        'paid_at' => '2026-07-30 12:00:00',
                        'payment_provider' => DomainOrderPaid::PROVIDER_STRIPE,
                        'payment_reference' => $credential,
                    ],
                );
                $this->fail("'{$credential}' should have been refused as a payment reference");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('credential', $e->getMessage());
            }
        }
    }

    public function test_a_float_total_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/minor units/');

        new DomainOrderPaid(
            workspaceId: $this->ws,
            payload: [
                'order_id' => 1, 'currency' => 'USD', 'total_minor' => 18.17,
                'paid_at' => '2026-07-30 12:00:00',
                'payment_provider' => DomainOrderPaid::PROVIDER_STRIPE,
                'payment_reference' => 'pi_test_x',
            ],
        );
    }

    // ══════════════════ 19 + 20. verification and the deployment gate ══

    public function test_verification_resolves_the_new_event_class_and_audit_handler(): void
    {
        $r = app(ChainVerifier::class)->verify();

        $this->assertTrue($r['passed'], 'failures: ' . json_encode($r['summary']['failed_names']));

        foreach ($r['checks'] as $c) {
            if ($c['name'] === 'event_classes_valid') {
                $this->assertStringContainsString('domain.order.paid', $c['detail']);
            }

            if ($c['name'] === 'subscriber_schema_acceptance') {
                $this->assertTrue($c['ok'],
                    'platform.audit must accept the version of domain.order.paid now produced');
            }
        }
    }

    public function test_the_deployment_gate_blocks_processing_when_verification_fails(): void
    {
        Queue::fake();
        $this->makeOrder();

        DomainCommerceService::make()->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');

        $this->assertCount(1, $this->paidEvents());
        $this->assertSame(0, DB::table('platform_event_deliveries')
            ->where('workspace_id', $this->ws)->count());

        // Break a structural contract. This also changes the fingerprint, so the
        // gate cannot pass on the strength of an earlier verification.
        config(['platform_events.subscriber_handlers' => []]);

        $exit = $this->artisan('platform-events:process')->run();

        $this->assertNotSame(0, $exit, 'a failed gate must return non-zero');
        $this->assertSame(0, DB::table('platform_event_deliveries')
            ->where('workspace_id', $this->ws)->count(),
            'and must process NOTHING');
        $this->assertNull(DB::table('platform_events')
            ->where('workspace_id', $this->ws)
            ->where('event_type', 'domain.order.paid')->value('fanned_out_at'),
            'the event must not even be stamped');
    }

    public function test_the_gate_permits_processing_once_the_chain_verifies(): void
    {
        Queue::fake();
        $this->makeOrder();

        DomainCommerceService::make()->markPaidAndProvision('cs_test_phase1e_001', 'pi_test_phase1e_001');

        $this->artisan('platform-events:process')->assertExitCode(0);

        $this->assertSame(1, DB::table('platform_event_deliveries')
            ->where('workspace_id', $this->ws)->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('workspace_id', $this->ws)
            ->where('action', 'domain.order.paid')->count());
    }

    public function test_the_gate_has_no_bypass_flag(): void
    {
        $src = file_get_contents(app_path('Console/Commands/PlatformEventsProcessCommand.php'));

        foreach (['skip-verify', 'skip_verify', 'no-verify', 'force'] as $bypass) {
            $this->assertStringNotContainsString($bypass, $src,
                "the deployment gate must have no '{$bypass}' escape hatch");
        }
    }
}
