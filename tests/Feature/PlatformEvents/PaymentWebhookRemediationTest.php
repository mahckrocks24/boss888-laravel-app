<?php

namespace Tests\Feature\PlatformEvents;

use App\Core\Billing\StripeService;
use App\Core\PlatformEvents\EventSystemHealth;
use App\Jobs\RegisterDomainJob;
use App\Models\DomainOrder;
use App\Services\Domains\DomainCommerceService;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Phase 1E.1 — the P0 remediation, HTTP semantics and fulfilment containment.
 *
 * The P0: the domain branch of the Stripe webhook called
 * app(DomainCommerceService::class), which is not container-resolvable, so every
 * genuine domain payment threw — and the route answered HTTP 200, so Stripe recorded
 * a successful delivery and never retried. A customer could be charged and the order
 * stay `pending` forever.
 */
class PaymentWebhookRemediationTest extends TestCase
{
    use IsolatedDatabase;

    private int $ws = 991001;
    private int $userId = 991901;

    private const SECRET = 'whsec_p1e1_local_only_aaaaaaaaaaaa';
    private const KEY = 'sk_test_p1e100000000000000000000000';
    private const SESSION = 'cs_test_p1e1_001';
    private const INTENT = 'pi_test_p1e1_001';

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 1E.1: never run against a shared database.
        $this->assertIsolatedDatabase();

        config([
            'platform_events.enabled' => true,
            'platform_events.producers' => ['domain.order.created' => true, 'domain.order.paid' => true],
            'platform_events.producer_workspace_allowlist' => [$this->ws],
            'platform_events.fanout.enabled' => true,
            'platform_events.delivery_worker.enabled' => true,
            'platform_events.subscribers' => ['platform.audit' => true],
            'billing.stripe.secret_key' => self::KEY,
            'billing.stripe.webhook_secret' => self::SECRET,
            // Containment ON by default in these tests.
            'domains.fulfilment.enabled' => false,
        ]);

        app()->forgetInstance(StripeService::class);
        Cache::forget(StripeService::WEBHOOK_FAILURE_STATE_KEY);
        Cache::forget(StripeService::WEBHOOK_SUCCESS_STATE_KEY);

        $this->cleanup();
        $this->seedTenants();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        DB::table('users')->where('id', $this->userId)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        Cache::forget(StripeService::WEBHOOK_FAILURE_STATE_KEY);
        Cache::forget(StripeService::WEBHOOK_SUCCESS_STATE_KEY);
        app()->forgetInstance(StripeService::class);
        parent::tearDown();
    }

    private function seedTenants(): void
    {
        if (! DB::table('users')->where('id', $this->userId)->exists()) {
            DB::table('users')->insert([
                'id' => $this->userId, 'name' => 'P1E1 Actor',
                'email' => 'p1e1-' . $this->userId . '@example.invalid',
                'password' => bcrypt('nope'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! DB::table('workspaces')->where('id', $this->ws)->exists()) {
            DB::table('workspaces')->insert([
                'id' => $this->ws, 'name' => 'P1E1 WS', 'slug' => 'p1e1-ws-' . $this->ws,
                'created_by' => $this->userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function cleanup(): void
    {
        $ids = DB::table('platform_events')->where('workspace_id', $this->ws)->pluck('event_id');
        DB::table('platform_event_deliveries')->whereIn('event_id', $ids)->delete();
        DB::table('platform_events')->where('workspace_id', $this->ws)->delete();
        DB::table('audit_logs')->where('workspace_id', $this->ws)->delete();
        DB::table('domain_order_items')->whereIn('domain_order_id',
            DB::table('domain_orders')->where('workspace_id', $this->ws)->pluck('id'))->delete();
        DB::table('domain_orders')->where('workspace_id', $this->ws)->delete();
    }

    private function makeOrder(string $session = self::SESSION): int
    {
        $id = DB::table('domain_orders')->insertGetId([
            'workspace_id' => $this->ws, 'user_id' => $this->userId,
            'status' => DomainOrder::STATUS_PENDING, 'currency' => 'USD',
            'subtotal_minor' => 1817, 'tax_minor' => 0, 'total_minor' => 1817,
            'cost_total_minor' => 1398, 'stripe_session_id' => $session,
            'metadata_json' => json_encode(['internal_test' => true, 'priced_at' => '2026-07-30T00:00:00+00:00']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('domain_order_items')->insert([
            'domain_order_id' => $id, 'workspace_id' => $this->ws,
            'domain' => 'p1e1-' . $id . '.com', 'tld' => 'com', 'years' => 1,
            'action' => 'register', 'provider' => 'namecheap',
            'registrar_cost_minor' => 1398, 'markup_minor' => 419, 'retail_minor' => 1817,
            'currency' => 'USD', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function body(string $eventId, string $session = self::SESSION, string $intent = self::INTENT): string
    {
        return json_encode([
            'id' => $eventId, 'object' => 'event', 'type' => 'checkout.session.completed',
            'created' => time(), 'livemode' => false,
            'data' => ['object' => [
                'id' => $session, 'object' => 'checkout.session', 'mode' => 'payment',
                'payment_intent' => $intent, 'payment_status' => 'paid',
                'metadata' => ['order_type' => 'domain'],
            ]],
        ], JSON_UNESCAPED_SLASHES);
    }

    private function sign(string $body, ?string $secret = null, ?int $ts = null): string
    {
        $ts ??= time();

        return "t={$ts},v1=" . hash_hmac('sha256', $ts . '.' . $body, $secret ?? self::SECRET);
    }

    private function hit(string $body, string $signature)
    {
        return $this->call('POST', '/api/webhook/stripe', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);
    }

    private function paidEvents()
    {
        return DB::table('platform_events')
            ->where('workspace_id', $this->ws)->where('event_type', 'domain.order.paid')->get();
    }

    // ══════════ 1 + 2. the P0 is fixed, permanently ══

    public function test_the_domain_webhook_branch_now_resolves_the_service(): void
    {
        $src = file_get_contents(app_path('Core/Billing/StripeService.php'));

        $this->assertStringNotContainsString(
            'app(\App\Services\Domains\DomainCommerceService::class)', $src,
            'the unresolvable container call must be gone'
        );
        $this->assertStringContainsString('DomainCommerceService::make()', $src,
            'it must use the factory every other caller uses');

        // And the container call genuinely still fails — proving the fix was necessary.
        $this->expectException(BindingResolutionException::class);
        app(DomainCommerceService::class);
    }

    public function test_the_webhook_reaches_the_payment_path_without_throwing(): void
    {
        Queue::fake();
        $orderId = $this->makeOrder();

        $body = $this->body('evt_p1e1_ok');
        $result = app(StripeService::class)->handleWebhook($body, $this->sign($body));

        $this->assertTrue($result['handled'], json_encode($result));
        $this->assertSame('marked_paid', $result['action']);
        $this->assertSame(StripeService::OUTCOME_OK, $result['outcome']);
        $this->assertSame($orderId, (int) $result['order_id']);
    }

    // ══════════ 3, 4, 5. HTTP semantics ══

    public function test_a_successfully_processed_webhook_returns_2xx(): void
    {
        Queue::fake();
        $this->makeOrder();

        $body = $this->body('evt_p1e1_200');
        $res = $this->hit($body, $this->sign($body));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($res->json()['received']);
    }

    public function test_a_duplicate_already_processed_event_returns_2xx(): void
    {
        Queue::fake();
        $this->makeOrder();

        $body = $this->body('evt_p1e1_dup');
        $this->hit($body, $this->sign($body));
        $second = $this->hit($body, $this->sign($body));

        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('already_processed', $second->json()['result']['action']);
        $this->assertCount(1, $this->paidEvents(), 'one payment, one event');
    }

    public function test_a_retryable_failure_returns_non_2xx(): void
    {
        Queue::fake();
        // No order for this session: a valid payment we cannot associate.
        $body = $this->body('evt_p1e1_unresolved', 'cs_test_p1e1_nonexistent');

        $res = $this->hit($body, $this->sign($body));

        $this->assertSame(503, $res->getStatusCode(),
            'a valid payment that cannot be recorded must NOT be acknowledged as received');
        $this->assertFalse($res->json()['received']);
        $this->assertSame(StripeService::OUTCOME_RETRYABLE, $res->json()['result']['outcome']);
    }

    public function test_an_invalid_signature_is_rejected_with_non_2xx(): void
    {
        $body = $this->body('evt_p1e1_badsig');
        $res = $this->hit($body, $this->sign($body, 'whsec_wrong_secret_entirely_xxxx'));

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(StripeService::OUTCOME_SIGNATURE_INVALID, $res->json()['result']['outcome']);
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $body = $this->body('evt_p1e1_tamper');
        $sig = $this->sign($body);
        $tampered = str_replace(self::INTENT, 'pi_test_ATTACKER', $body);

        $res = $this->hit($tampered, $sig);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertCount(0, $this->paidEvents());
    }

    public function test_no_internal_exception_detail_is_returned_to_stripe(): void
    {
        Queue::fake();
        $body = $this->body('evt_p1e1_leak', 'cs_test_p1e1_nonexistent');

        $res = $this->hit($body, $this->sign($body));
        $payload = json_encode($res->json());

        foreach (['BindingResolutionException', 'NamecheapClient', 'vendor/laravel',
                  'Stack trace', self::SECRET, self::KEY] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload,
                "the response must not leak '{$forbidden}' to Stripe");
        }
    }

    // ══════════ 8, 9, 10, 11, 13. idempotency and atomicity ══

    public function test_the_same_event_pays_once_and_a_second_event_id_does_not_pay_again(): void
    {
        Queue::fake();
        $orderId = $this->makeOrder();

        $a = $this->body('evt_p1e1_A');
        $b = $this->body('evt_p1e1_B');   // different event id, same session + intent

        $this->hit($a, $this->sign($a));
        $second = $this->hit($b, $this->sign($b));

        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('already_processed', $second->json()['result']['action']);
        $this->assertCount(1, $this->paidEvents());
        $this->assertSame(1, DB::table('domain_orders')->where('id', $orderId)->whereNotNull('paid_at')->count());
    }

    public function test_a_failed_transaction_leaves_the_order_unpaid_and_retryable(): void
    {
        Queue::fake();
        $orderId = $this->makeOrder();

        DB::beginTransaction();
        DomainCommerceService::make()->markPaidAndProvision(self::SESSION, self::INTENT);
        $this->assertNotNull(DB::table('domain_orders')->where('id', $orderId)->value('paid_at'));
        DB::rollBack();

        // Nothing survived — so a retry is still possible and still correct.
        $this->assertNull(DB::table('domain_orders')->where('id', $orderId)->value('paid_at'));
        $this->assertCount(0, $this->paidEvents(),
            'the event must not exist for a payment that rolled back');

        $result = DomainCommerceService::make()->markPaidAndProvision(self::SESSION, self::INTENT);
        $this->assertSame('marked_paid', $result['action'], 'the retry succeeds');
        $this->assertCount(1, $this->paidEvents());
    }

    // ══════════ 14, 15, 16. fulfilment containment ══

    public function test_fulfilment_disabled_pays_and_records_but_queues_nothing(): void
    {
        Queue::fake();
        config(['domains.fulfilment.enabled' => false]);

        $orderId = $this->makeOrder();
        $result = DomainCommerceService::make()->markPaidAndProvision(self::SESSION, self::INTENT);

        $this->assertSame('marked_paid', $result['action']);
        $this->assertSame(0, $result['dispatched']);
        $this->assertSame('suppressed', $result['fulfilment']);

        Queue::assertNothingPushed();

        $order = DB::table('domain_orders')->where('id', $orderId)->first();
        $this->assertNotNull($order->paid_at, 'payment still commits');
        $this->assertSame(DomainOrder::STATUS_PAID, $order->status,
            'it must stay paid, NOT advance to provisioning');

        $this->assertCount(1, $this->paidEvents(), 'the paid event is still recorded');
    }

    public function test_fulfilment_disabled_does_not_claim_registration_completed(): void
    {
        Queue::fake();
        config(['domains.fulfilment.enabled' => false]);

        $orderId = $this->makeOrder();
        DomainCommerceService::make()->markPaidAndProvision(self::SESSION, self::INTENT);

        $order = DB::table('domain_orders')->where('id', $orderId)->first();
        $meta = json_decode((string) $order->metadata_json, true);

        $this->assertTrue($meta['fulfilment_suppressed']);
        $this->assertSame('pending_operator_fulfilment', $meta['registration_state']);
        $this->assertNotSame(DomainOrder::STATUS_COMPLETED, $order->status);

        // Pre-existing provenance survives the merge.
        $this->assertArrayHasKey('priced_at', $meta);
        $this->assertTrue($meta['internal_test']);

        // The item is untouched: nothing claims it was registered.
        $item = DB::table('domain_order_items')->where('domain_order_id', $orderId)->first();
        $this->assertSame('pending', $item->status);
        $this->assertNull($item->registered_at);
    }

    public function test_fulfilment_enabled_preserves_the_existing_dispatch_behaviour(): void
    {
        Queue::fake();
        config(['domains.fulfilment.enabled' => true]);

        $orderId = $this->makeOrder();
        $result = DomainCommerceService::make()->markPaidAndProvision(self::SESSION, self::INTENT);

        $this->assertSame(1, $result['dispatched']);
        $this->assertSame('dispatched', $result['fulfilment']);
        Queue::assertPushed(RegisterDomainJob::class, 1);

        $this->assertSame(DomainOrder::STATUS_PROVISIONING,
            DB::table('domain_orders')->where('id', $orderId)->value('status'));
    }

    public function test_the_code_default_preserves_the_intended_production_behaviour(): void
    {
        $src = file_get_contents(config_path('domains.php'));

        $this->assertStringContainsString("env('DOMAINS_FULFILMENT_ENABLED', true)", $src,
            'the CODE default must remain true; only the deployed value is false');
    }

    public function test_re_enabling_does_not_replay_a_backlog(): void
    {
        Queue::fake();
        config(['domains.fulfilment.enabled' => false]);

        $orderId = $this->makeOrder();
        DomainCommerceService::make()->markPaidAndProvision(self::SESSION, self::INTENT);
        Queue::assertNothingPushed();

        // Re-enable. Nothing may be dispatched for the historical order without an
        // explicit fulfilment request — there is no automatic catch-up.
        config(['domains.fulfilment.enabled' => true]);

        $again = DomainCommerceService::make()->markPaidAndProvision(self::SESSION, self::INTENT);

        $this->assertSame('already_processed', $again['action']);
        Queue::assertNothingPushed();
        $this->assertSame(DomainOrder::STATUS_PAID,
            DB::table('domain_orders')->where('id', $orderId)->value('status'));
    }

    public function test_the_payment_path_makes_no_provider_or_stripe_api_call(): void
    {
        $src = file_get_contents(app_path('Services/Domains/DomainCommerceService.php'));
        $start = strpos($src, 'public function markPaidAndProvision(');
        $rest = substr($src, (int) $start);
        $body = substr($rest, 0, (int) strpos($rest, "\n    }\n"));

        foreach (['$this->registrar', 'NamecheapRegistrarConnector', 'searchDomain',
                  'quoteRegistration', '\Stripe\\', 'PaymentIntent'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    // ══════════ 21, 22. durable failure visibility ══

    public function test_a_webhook_failure_is_recorded_durably_and_surfaces_in_health(): void
    {
        Queue::fake();
        $body = $this->body('evt_p1e1_fail', 'cs_test_p1e1_nonexistent');

        $this->hit($body, $this->sign($body));

        $state = Cache::get(StripeService::WEBHOOK_FAILURE_STATE_KEY);
        $this->assertIsArray($state);
        $this->assertSame(1, $state['count']);
        $this->assertSame(1, $state['retryable_count']);
        $this->assertSame('evt_p1e1_fail', $state['last']['stripe_event_id']);
        $this->assertTrue($state['last']['retryable']);

        $h = app(EventSystemHealth::class)->report();
        $w = $h['domain_payment_webhook'];

        $this->assertSame(1, $w['unacknowledged_failures']);
        $this->assertSame('evt_p1e1_fail', $w['last_failure']['stripe_event_id']);
        $this->assertFalse($w['fulfilment_enabled']);
        $this->assertSame(EventSystemHealth::CRITICAL, $h['severity']);
    }

    public function test_a_later_successful_webhook_does_not_clear_failure_history(): void
    {
        Queue::fake();

        $bad = $this->body('evt_p1e1_bad', 'cs_test_p1e1_nonexistent');
        $this->hit($bad, $this->sign($bad));
        $this->assertSame(1, Cache::get(StripeService::WEBHOOK_FAILURE_STATE_KEY)['count']);

        $this->makeOrder();
        $good = $this->body('evt_p1e1_good');
        $this->assertSame(200, $this->hit($good, $this->sign($good))->getStatusCode());

        $state = Cache::get(StripeService::WEBHOOK_FAILURE_STATE_KEY);
        $this->assertIsArray($state, 'a clean webhook must NOT erase the incident');
        $this->assertSame(1, $state['count']);

        $h = app(EventSystemHealth::class)->report();
        $this->assertSame(1, $h['domain_payment_webhook']['unacknowledged_failures']);
        $this->assertNotNull($h['domain_payment_webhook']['last_success'],
            'the success is recorded separately');
        $this->assertSame(EventSystemHealth::CRITICAL, $h['severity'],
            'the unresolved incident still degrades health');
    }

    public function test_the_failure_record_carries_no_secret_or_raw_body(): void
    {
        Queue::fake();
        $body = $this->body('evt_p1e1_nosecret', 'cs_test_p1e1_nonexistent');
        $this->hit($body, $this->sign($body));

        $serialised = json_encode(Cache::get(StripeService::WEBHOOK_FAILURE_STATE_KEY));

        // The Stripe event TYPE, session and payment references are REQUIRED evidence.
        // What must never appear is credential or signature material, or the raw body.
        foreach ([self::SECRET, self::KEY, 'whsec_', 'sk_test_', 'v1=', 'livemode', '"data"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $serialised,
                "the failure record must not contain '{$forbidden}'");
        }
    }

    // ══════════ 23. the producer stays disabled in production config ══

    public function test_the_paid_producer_ships_disabled(): void
    {
        $src = file_get_contents(config_path('platform_events.php'));

        $this->assertStringContainsString("env('PLATFORM_EVENTS_DOMAIN_ORDER_PAID', false)", $src,
            'the paid producer must default to false in committed config');
    }

    // ══════════ 25. isolation is real ══

    public function test_this_suite_runs_in_an_isolated_database(): void
    {
        $db = $this->isolatedDatabaseName();

        $this->assertNotSame('levelup_staging', $db);
        $this->assertNotSame('levelup_test', $db);
        $this->assertMatchesRegularExpression('/^levelup_[a-z0-9]+_test$/', $db);
    }
}
