<?php

namespace Tests\Feature\PlatformEvents;

use App\Core\Billing\StripeService;
use App\Models\DomainOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Phase 1E.2 — the governed setup command and the one-time fixture signature path.
 *
 * The fixture path is a deliberate, temporary hole in webhook authentication. Every
 * test here exists to prove it is not a backdoor: it requires ALL of an explicit
 * flag, loopback origin, an exact event id, an exact session, an exact payment
 * intent, an exact order in workspace 1, test mode, fulfilment disabled and the paid
 * producer enabled. Remove any one and it must vanish.
 */
class FixtureControlsTest extends TestCase
{
    use IsolatedDatabase;

    private int $ws = 992001;
    private int $wsOther = 992002;
    private int $userId = 992901;

    private const FIX_SECRET = 'whsec_fixture_test_only_bbbbbbbbbbbb';
    private const LIVE_SECRET = 'whsec_live_test_only_cccccccccccc';
    private const EVENT = 'evt_test_fixture_001';
    private const SESSION = 'cs_test_fixture_001';
    private const INTENT = 'pi_test_fixture_001';

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedDatabase();

        config([
            'platform_events.enabled' => true,
            'platform_events.producers' => ['domain.order.created' => true, 'domain.order.paid' => true],
            'platform_events.producer_workspace_allowlist' => [$this->ws],
            'platform_events.fanout.enabled' => true,
            'platform_events.delivery_worker.enabled' => true,
            'platform_events.subscribers' => ['platform.audit' => true],
            'billing.stripe.secret_key' => 'sk_test_fixture0000000000000000000',
            'billing.stripe.webhook_secret' => self::LIVE_SECRET,
            'domains.fulfilment.enabled' => false,
            'domains.fixture_replay' => [
                'enabled' => true,
                'secret' => self::FIX_SECRET,
                'event_id' => self::EVENT,
                'session_id' => self::SESSION,
                'payment_intent_id' => self::INTENT,
                'order_id' => 0,          // set after the order exists
                'workspace_id' => $this->ws,
                'event_type' => 'checkout.session.completed',
                // The suite runs as `testing`; the control itself is unchanged.
                'environments' => ['testing', 'staging', 'production'],
            ],
        ]);

        app()->forgetInstance(StripeService::class);
        $this->cleanup();
        $this->seedTenants();
        $this->orderId = $this->makeOrder();
        config(['domains.fixture_replay.order_id' => $this->orderId]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        DB::table('users')->where('id', $this->userId)->delete();
        DB::table('workspaces')->whereIn('id', [$this->ws, $this->wsOther])->delete();
        app()->forgetInstance(StripeService::class);
        parent::tearDown();
    }

    private function seedTenants(): void
    {
        if (! DB::table('users')->where('id', $this->userId)->exists()) {
            DB::table('users')->insert([
                'id' => $this->userId, 'name' => 'P1E2', 'email' => 'p1e2-' . $this->userId . '@example.invalid',
                'password' => bcrypt('nope'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([$this->ws, $this->wsOther] as $id) {
            if (! DB::table('workspaces')->where('id', $id)->exists()) {
                DB::table('workspaces')->insert([
                    'id' => $id, 'name' => 'P1E2 WS ' . $id, 'slug' => 'p1e2-ws-' . $id,
                    'created_by' => $this->userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function cleanup(): void
    {
        $ids = DB::table('platform_events')->whereIn('workspace_id', [$this->ws, $this->wsOther])->pluck('event_id');
        DB::table('platform_event_deliveries')->whereIn('event_id', $ids)->delete();
        DB::table('platform_events')->whereIn('workspace_id', [$this->ws, $this->wsOther])->delete();
        DB::table('audit_logs')->whereIn('workspace_id', [$this->ws, $this->wsOther])->delete();
        DB::table('domain_order_items')->whereIn('domain_order_id',
            DB::table('domain_orders')->whereIn('workspace_id', [$this->ws, $this->wsOther])->pluck('id'))->delete();
        DB::table('domain_orders')->whereIn('workspace_id', [$this->ws, $this->wsOther])->delete();
    }

    private function makeOrder(?int $ws = null, bool $internal = true, ?string $session = self::SESSION, ?string $status = null): int
    {
        $ws ??= $this->ws;

        $id = DB::table('domain_orders')->insertGetId([
            'workspace_id' => $ws, 'user_id' => $this->userId,
            'status' => $status ?? DomainOrder::STATUS_PENDING, 'currency' => 'USD',
            'subtotal_minor' => 1817, 'tax_minor' => 0, 'total_minor' => 1817, 'cost_total_minor' => 1398,
            'stripe_session_id' => $session,
            'metadata_json' => json_encode($internal ? ['internal_test' => true] : ['priced_at' => 'x']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('domain_order_items')->insert([
            'domain_order_id' => $id, 'workspace_id' => $ws, 'domain' => 'fix-' . $id . '.com',
            'tld' => 'com', 'years' => 1, 'action' => 'register', 'provider' => 'namecheap',
            'registrar_cost_minor' => 1398, 'markup_minor' => 419, 'retail_minor' => 1817,
            'currency' => 'USD', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function body(array $over = []): string
    {
        $o = array_merge([
            'event_id' => self::EVENT, 'type' => 'checkout.session.completed', 'livemode' => false,
            'session' => self::SESSION, 'intent' => self::INTENT,
        ], $over);

        return json_encode([
            'id' => $o['event_id'], 'object' => 'event', 'type' => $o['type'],
            'created' => time(), 'livemode' => $o['livemode'],
            'data' => ['object' => [
                'id' => $o['session'], 'object' => 'checkout.session', 'mode' => 'payment',
                'payment_status' => 'paid', 'payment_intent' => $o['intent'],
                'metadata' => ['order_type' => 'domain'],
            ]],
        ], JSON_UNESCAPED_SLASHES);
    }

    private function sign(string $body, string $secret): string
    {
        $ts = time();

        return "t={$ts},v1=" . hash_hmac('sha256', $ts . '.' . $body, $secret);
    }

    /** Call the real verifier with an explicit source ip. */
    private function verify(string $body, string $secret = self::FIX_SECRET, ?string $ip = '127.0.0.1'): array
    {
        return app(StripeService::class)->handleWebhook($body, $this->sign($body, $secret), $ip);
    }

    private function assertRejected(array $result, string $why): void
    {
        $this->assertFalse($result['handled'] ?? true, $why);
        $this->assertSame(StripeService::OUTCOME_SIGNATURE_INVALID, $result['outcome'] ?? null, $why);
    }

    // ══════════ 17, 18, 19. the authorised fixture works ══

    public function test_a_valid_local_fixture_passes_real_stripe_verification(): void
    {
        Queue::fake();

        $r = $this->verify($this->body());

        $this->assertTrue($r['handled'], json_encode($r));
        $this->assertSame('marked_paid', $r['action']);
        $this->assertSame($this->orderId, (int) $r['order_id']);
    }

    public function test_a_valid_fixture_pays_exactly_one_order_and_creates_one_event(): void
    {
        Queue::fake();

        $this->verify($this->body());

        $this->assertSame(1, DB::table('domain_orders')->where('id', $this->orderId)->whereNotNull('paid_at')->count());
        $this->assertSame(0, DB::table('domain_orders')->where('workspace_id', $this->ws)
            ->where('id', '!=', $this->orderId)->whereNotNull('paid_at')->count());
        $this->assertSame(1, DB::table('platform_events')
            ->where('workspace_id', $this->ws)->where('event_type', 'domain.order.paid')->count());
    }

    // ══════════ 20, 21, 22, 23, 24. duplicate and containment ══

    public function test_a_duplicate_fixture_produces_no_second_transition_or_event(): void
    {
        Queue::fake();

        $this->verify($this->body());
        $paidAt = DB::table('domain_orders')->where('id', $this->orderId)->value('paid_at');

        $second = $this->verify($this->body());

        $this->assertSame('already_processed', $second['action']);
        $this->assertSame($paidAt, DB::table('domain_orders')->where('id', $this->orderId)->value('paid_at'));
        $this->assertSame(1, DB::table('platform_events')
            ->where('workspace_id', $this->ws)->where('event_type', 'domain.order.paid')->count());
    }

    public function test_no_registration_job_notification_or_memory_write_occurs(): void
    {
        Queue::fake();
        $notif = DB::table('notifications')->count();
        $mem = DB::table('workspace_memory')->count();

        $this->verify($this->body());

        Queue::assertNothingPushed();
        $this->assertSame($notif, DB::table('notifications')->count());
        $this->assertSame($mem, DB::table('workspace_memory')->count());
        $this->assertSame(DomainOrder::STATUS_PAID,
            DB::table('domain_orders')->where('id', $this->orderId)->value('status'));
    }

    // ══════════ 8–16. every condition removed must reject ══

    public function test_the_fixture_is_rejected_without_the_explicit_flag(): void
    {
        config(['domains.fixture_replay.enabled' => false]);
        $this->assertRejected($this->verify($this->body()), 'no flag, no fixture path');
    }

    public function test_the_fixture_is_rejected_from_a_non_local_origin(): void
    {
        $this->assertRejected($this->verify($this->body(), self::FIX_SECRET, '203.0.113.9'),
            'external traffic must never authenticate with the fixture secret');
    }

    public function test_the_fixture_is_rejected_with_no_source_ip_at_all(): void
    {
        $this->assertRejected($this->verify($this->body(), self::FIX_SECRET, null), 'fail closed');
    }

    public function test_the_fixture_is_rejected_for_the_wrong_workspace(): void
    {
        config(['domains.fixture_replay.workspace_id' => $this->wsOther]);
        $this->assertRejected($this->verify($this->body()), 'workspace must match exactly');
    }

    public function test_the_fixture_is_rejected_for_the_wrong_order(): void
    {
        config(['domains.fixture_replay.order_id' => $this->orderId + 999]);
        $this->assertRejected($this->verify($this->body()), 'order must match exactly');
    }

    public function test_the_fixture_is_rejected_for_the_wrong_event_id(): void
    {
        $this->assertRejected($this->verify($this->body(['event_id' => 'evt_test_not_authorised'])),
            'no wildcard on the event id');
    }

    public function test_the_fixture_is_rejected_for_the_wrong_session(): void
    {
        $this->assertRejected($this->verify($this->body(['session' => 'cs_test_other'])), 'session must match');
    }

    public function test_the_fixture_is_rejected_for_the_wrong_payment_intent(): void
    {
        $this->assertRejected($this->verify($this->body(['intent' => 'pi_test_other'])), 'intent must match');
    }

    public function test_the_fixture_is_rejected_for_a_live_mode_object(): void
    {
        $this->assertRejected($this->verify($this->body(['livemode' => true])), 'test mode only');
    }

    public function test_the_fixture_is_rejected_when_fulfilment_is_enabled(): void
    {
        config(['domains.fulfilment.enabled' => true]);
        $this->assertRejected($this->verify($this->body()),
            'containment must be in force, or the replay could queue a real registration');
    }

    public function test_the_fixture_is_rejected_when_the_paid_producer_is_disabled(): void
    {
        config(['platform_events.producers' => ['domain.order.created' => true, 'domain.order.paid' => false]]);
        $this->assertRejected($this->verify($this->body()), 'a replay that records nothing proves nothing');
    }

    public function test_the_fixture_path_refuses_to_be_a_second_live_secret(): void
    {
        // If the two secrets were equal the LIVE path would verify first, so this is
        // asserted at the guard rather than through the response: the fixture branch
        // must never accept a secret identical to the live one.
        $src = file_get_contents(app_path('Core/Billing/StripeService.php'));

        $this->assertStringContainsString('hash_equals($this->webhookSecret, $secret)', $src,
            'the fixture verifier must refuse a secret equal to the live webhook secret');
    }

    public function test_the_fixture_is_rejected_outside_an_approved_environment(): void
    {
        config(['domains.fixture_replay.environments' => ['production']]);

        $this->assertRejected($this->verify($this->body()),
            'the environment condition must close the path');
    }

    public function test_a_blank_fixture_secret_rejects(): void
    {
        config(['domains.fixture_replay.secret' => '']);
        $this->assertRejected($this->verify($this->body()), 'blank means absent');
    }

    public function test_ordinary_traffic_signed_with_the_live_secret_still_works(): void
    {
        Queue::fake();

        $r = $this->verify($this->body(), self::LIVE_SECRET, '203.0.113.9');

        $this->assertTrue($r['handled'], 'the fixture control must not break normal webhooks');
        $this->assertSame('marked_paid', $r['action']);
    }

    // ══════════ 1–7. the setup command ══

    public function test_the_setup_command_dry_run_is_read_only(): void
    {
        $fresh = $this->makeOrder(session: null);
        $before = DB::table('domain_orders')->where('id', $fresh)->first();

        $this->artisan('domains:attach-test-session', [
            'order' => $fresh, '--session' => 'cs_test_dryrun', '--dry-run' => true,
        ]);

        $after = DB::table('domain_orders')->where('id', $fresh)->first();
        $this->assertEquals((array) $before, (array) $after, 'a dry run must change nothing');
    }

    public function test_the_setup_command_refuses_a_non_workspace_one_order(): void
    {
        $other = $this->makeOrder(ws: $this->wsOther, session: null);

        $this->artisan('domains:attach-test-session', [
            'order' => $other, '--session' => 'cs_test_x', '--dry-run' => true,
        ])->assertExitCode(1);
    }

    public function test_the_setup_command_refuses_a_customer_order(): void
    {
        $customer = $this->makeOrder(internal: false, session: null);

        $this->artisan('domains:attach-test-session', [
            'order' => $customer, '--session' => 'cs_test_x', '--dry-run' => true,
        ])->assertExitCode(1);
    }

    public function test_the_setup_command_refuses_a_paid_order(): void
    {
        $paid = $this->makeOrder(session: null);
        DB::table('domain_orders')->where('id', $paid)->update(['paid_at' => now()]);

        $this->artisan('domains:attach-test-session', [
            'order' => $paid, '--session' => 'cs_test_x', '--dry-run' => true,
        ])->assertExitCode(1);
    }

    public function test_the_setup_command_refuses_a_cancelled_order(): void
    {
        $cancelled = $this->makeOrder(session: null, status: DomainOrder::STATUS_CANCELLED);

        $this->artisan('domains:attach-test-session', [
            'order' => $cancelled, '--session' => 'cs_test_x', '--dry-run' => true,
        ])->assertExitCode(1);
    }

    public function test_the_setup_command_refuses_to_overwrite_an_existing_reference(): void
    {
        // $this->orderId already carries a session reference.
        $this->artisan('domains:attach-test-session', [
            'order' => $this->orderId, '--session' => 'cs_test_overwrite', '--dry-run' => true,
        ])->assertExitCode(1);
    }

    public function test_the_setup_command_refuses_a_non_test_session_identifier(): void
    {
        $fresh = $this->makeOrder(session: null);

        $this->artisan('domains:attach-test-session', [
            'order' => $fresh, '--session' => 'cs_live_real_session', '--dry-run' => true,
        ])->assertExitCode(1);
    }

    public function test_the_setup_command_requires_a_matching_confirmation_token(): void
    {
        $fresh = $this->makeOrder(session: null);

        $this->artisan('domains:attach-test-session', [
            'order' => $fresh, '--session' => 'cs_test_confirm', '--confirm' => 'not-the-token',
        ])->assertExitCode(1);

        $this->assertNull(DB::table('domain_orders')->where('id', $fresh)->value('stripe_session_id'));
    }

    // ══════════ 25. the controls ship disabled ══

    public function test_the_fixture_controls_ship_disabled_and_blank(): void
    {
        $src = file_get_contents(config_path('domains.php'));

        $this->assertStringContainsString("env('DOMAINS_FIXTURE_REPLAY_ENABLED', false)", $src);
        $this->assertStringContainsString("env('DOMAINS_FIXTURE_REPLAY_SECRET', '')", $src);
        $this->assertStringContainsString("env('DOMAINS_FIXTURE_EVENT_ID', '')", $src);
        $this->assertStringContainsString("env('DOMAINS_FIXTURE_ORDER_ID', 0)", $src);
    }

    public function test_there_is_no_route_or_ui_for_either_control(): void
    {
        foreach (glob(base_path('routes/*.php')) as $file) {
            $src = file_get_contents($file);

            foreach (['attach-test-session', 'replay-test-webhook', 'fixture_replay'] as $needle) {
                $this->assertStringNotContainsString($needle, $src,
                    'the fixture controls must never be reachable over HTTP');
            }
        }
    }
}
