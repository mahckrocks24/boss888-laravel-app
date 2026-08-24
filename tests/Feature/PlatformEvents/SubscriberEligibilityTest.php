<?php

namespace Tests\Feature\PlatformEvents;

use App\Core\PlatformEvents\DeliveryWorker;
use App\Core\PlatformEvents\EventFanOut;
use App\Core\PlatformEvents\Outbox;
use App\Core\PlatformEvents\PlatformEvent;
use App\Core\PlatformEvents\SubscriberDeclaration;
use App\Core\PlatformEvents\SubscriberRegistry;
use App\Core\PlatformEvents\Subscribers\AuditSubscriber;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Phase 1C.1 — SUBSCRIBER ELIGIBILITY IS INDEPENDENT OF SUBSCRIBER EXECUTION.
 *
 * The defect this file exists to prevent: in Phase 1C, fan-out chose subscribers
 * with SubscriberRegistry::active(), which required the per-subscriber EXECUTION
 * flag to be on. The audit flag was off when event #1 was fanned out, so no
 * delivery row was created — and because fan-out still stamped fanned_out_at, the
 * obligation was lost permanently rather than merely postponed.
 *
 * The rule now: eligibility creates the obligation, execution decides when it runs.
 */
class SubscriberEligibilityTest extends TestCase
{
    use IsolatedDatabase;

    private int $ws = 996001;
    private int $wsOutside = 996002;
    private int $userId = 996901;

    private const AFTER_ACTIVATION = '2026-07-30 10:00:00';
    private const BEFORE_ACTIVATION = '2026-07-01 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 1E.1: never run against a shared database.
        $this->assertIsolatedDatabase();

        config([
            'platform_events.enabled' => true,
            'platform_events.producers' => ['domain.order.created' => true],
            'platform_events.producer_workspace_allowlist' => [$this->ws],
            'platform_events.fanout.enabled' => true,
            'platform_events.delivery_worker.enabled' => true,
            'platform_events.subscribers' => ['platform.audit' => true],
        ]);

        $this->cleanup();
        $this->seedTenants();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        DB::table('users')->where('id', $this->userId)->delete();
        DB::table('workspaces')->whereIn('id', [$this->ws, $this->wsOutside])->delete();
        parent::tearDown();
    }

    private function seedTenants(): void
    {
        if (! DB::table('users')->where('id', $this->userId)->exists()) {
            DB::table('users')->insert([
                'id' => $this->userId,
                'name' => 'Phase1C1 Actor',
                'email' => 'phase1c1-' . $this->userId . '@example.invalid',
                'password' => bcrypt('not-a-real-password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([$this->ws, $this->wsOutside] as $id) {
            if (! DB::table('workspaces')->where('id', $id)->exists()) {
                DB::table('workspaces')->insert([
                    'id' => $id,
                    'name' => 'Phase1C1 WS ' . $id,
                    'slug' => 'phase1c1-ws-' . $id,
                    'created_by' => $this->userId,
                    'created_at' => now(),
                    'updated_at' => now(),
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
    }

    private function recordEvent(int $orderId = 960001, ?int $ws = null, ?string $recordedAt = null): string
    {
        $ws ??= $this->ws;
        $eventId = (string) Str::uuid();

        DB::table('platform_events')->insert([
            'event_id' => $eventId,
            'workspace_id' => $ws,
            'event_type' => 'domain.order.created',
            'schema_version' => 1,
            'capability_key' => 'domain.register',
            'actor_type' => 'user',
            'actor_id' => $this->userId,
            'subject_type' => 'domain_order',
            'subject_id' => (string) $orderId,
            'correlation_id' => (string) Str::uuid(),
            'payload_json' => json_encode([
                'order_id' => $orderId, 'currency' => 'USD', 'subtotal_minor' => 1817,
                'item_count' => 1, 'domains' => ['eligibility-test.com'],
            ]),
            'sensitivity' => 'internal',
            'status' => Outbox::STATUS_PENDING,
            'occurred_at' => $recordedAt ?? self::AFTER_ACTIVATION,
            'recorded_at' => $recordedAt ?? self::AFTER_ACTIVATION,
            'attempt_count' => 0,
        ]);

        return $eventId;
    }

    private function deliveries(?string $eventId = null)
    {
        $q = DB::table('platform_event_deliveries')->whereIn('workspace_id', [$this->ws, $this->wsOutside]);

        if ($eventId !== null) {
            $q->where('event_id', $eventId);
        }

        return $q->get();
    }

    private function auditRows()
    {
        return DB::table('audit_logs')->whereIn('workspace_id', [$this->ws, $this->wsOutside])->get();
    }

    /** A declaration identical to the live one except for the fields under test. */
    private function declaration(?string $disabledAt = null, ?string $retiredAt = null): SubscriberDeclaration
    {
        return new SubscriberDeclaration(
            key: AuditSubscriber::KEY,
            version: AuditSubscriber::VERSION,
            accepts: ['domain.order.created' => [1]],
            activatedAt: '2026-07-29 20:00:00',
            maxAttempts: 5,
            backoff: [30, 120, 600, 3600],
            sensitivityAllowance: [
                PlatformEvent::SENSITIVITY_PUBLIC,
                PlatformEvent::SENSITIVITY_INTERNAL,
                PlatformEvent::SENSITIVITY_RESTRICTED,
            ],
            disabledForFutureEligibilityAt: $disabledAt,
            retiredAt: $retiredAt,
        );
    }

    // ═══════════════════════ THE REGRESSION ITSELF ══

    /**
     * The single most important assertion in this file: eligibility must not read
     * the execution flag. If this fails, Phase 1C event #1 can happen again.
     */
    public function test_eligibility_ignores_the_execution_flag(): void
    {
        config(['platform_events.subscribers' => ['platform.audit' => false]]);

        $this->assertSame(['platform.audit'], array_keys(SubscriberRegistry::eligible()),
            'a paused subscriber is still ELIGIBLE — it still has obligations');
        $this->assertSame([], array_keys(SubscriberRegistry::executable()),
            'but it is not EXECUTABLE while paused');
    }

    /** Belt and braces: prove it at the source level too. */
    public function test_the_eligible_selector_does_not_read_the_execution_flag(): void
    {
        $src = file_get_contents(app_path('Core/PlatformEvents/SubscriberRegistry.php'));

        $eligible = substr($src, strpos($src, 'public static function eligible'));
        $eligible = substr($eligible, 0, strpos($eligible, 'public static function executable'));

        $this->assertStringNotContainsString('platform_events.subscribers', $eligible,
            'eligible() must not consult the execution flag');
        $this->assertStringNotContainsString('fanout.enabled', $eligible,
            'eligible() must not consult fan-out infrastructure state either');
    }

    public function test_fanout_selects_eligible_subscribers_not_executable_ones(): void
    {
        $src = file_get_contents(app_path('Core/PlatformEvents/EventFanOut.php'));

        $this->assertStringContainsString('SubscriberRegistry::eligible()', $src);
        $this->assertStringNotContainsString('SubscriberRegistry::executable()', $src,
            'fan-out must never gate obligation creation on execution state');
    }

    public function test_the_delivery_worker_gates_on_executable(): void
    {
        $src = file_get_contents(app_path('Core/PlatformEvents/DeliveryWorker.php'));

        $this->assertStringContainsString('SubscriberRegistry::executable()', $src);
    }

    // ═══════════════════════ 1. active + execution enabled ══

    public function test_active_subscriber_with_execution_enabled_delivers(): void
    {
        $eventId = $this->recordEvent(960101);

        (new EventFanOut())->run();

        $d = $this->deliveries($eventId);
        $this->assertCount(1, $d, 'exactly one delivery obligation');
        $this->assertSame(DeliveryWorker::STATUS_PENDING, $d->first()->status);

        (new DeliveryWorker())->run();

        $d = $this->deliveries($eventId);
        $this->assertCount(1, $d);
        $this->assertSame(DeliveryWorker::STATUS_DELIVERED, $d->first()->status);
        $this->assertCount(1, $this->auditRows(), 'one audit row');
    }

    // ═══════════════════════ 2. active + execution paused ══

    public function test_execution_paused_still_creates_a_pending_delivery(): void
    {
        config(['platform_events.subscribers' => ['platform.audit' => false]]);

        $eventId = $this->recordEvent(960102);

        (new EventFanOut())->run();

        $d = $this->deliveries($eventId);
        $this->assertCount(1, $d,
            'THE PHASE 1C DEFECT: a paused subscriber must still receive its obligation');
        $this->assertSame(DeliveryWorker::STATUS_PENDING, $d->first()->status);

        (new DeliveryWorker())->run();

        $this->assertSame(DeliveryWorker::STATUS_PENDING, $this->deliveries($eventId)->first()->status,
            'still pending — nothing executed');
        $this->assertCount(0, $this->auditRows(), 'no audit row while paused');
    }

    public function test_the_event_is_still_marked_fanned_out_while_paused(): void
    {
        config(['platform_events.subscribers' => ['platform.audit' => false]]);

        $eventId = $this->recordEvent(960103);
        (new EventFanOut())->run();

        $event = DB::table('platform_events')->where('event_id', $eventId)->first();

        // Stamping fanned_out_at is now SAFE precisely because the obligation was
        // recorded. In Phase 1C it was stamped with no obligation, which is what
        // made the loss permanent.
        $this->assertNotNull($event->fanned_out_at);
        $this->assertSame(1, (int) $event->delivery_count);
    }

    // ═══════════════════════ 3. execution enabled later ══

    public function test_resuming_execution_processes_the_preserved_delivery(): void
    {
        config(['platform_events.subscribers' => ['platform.audit' => false]]);

        $eventId = $this->recordEvent(960104);
        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $this->assertCount(0, $this->auditRows());
        $deliveryId = $this->deliveries($eventId)->first()->id;

        // Resume execution.
        config(['platform_events.subscribers' => ['platform.audit' => true]]);

        (new EventFanOut())->run();   // must not create a second obligation
        (new DeliveryWorker())->run();

        $d = $this->deliveries($eventId);
        $this->assertCount(1, $d, 'no duplicate delivery was created on resume');
        $this->assertSame($deliveryId, (int) $d->first()->id, 'the SAME row processed');
        $this->assertSame(DeliveryWorker::STATUS_DELIVERED, $d->first()->status);
        $this->assertCount(1, $this->auditRows(), 'exactly one audit row after resume');
    }

    // ═══════════════════════ 4. disabled for future eligibility ══

    public function test_an_event_at_or_after_the_disable_timestamp_gets_no_obligation(): void
    {
        $decl = $this->declaration(disabledAt: '2026-07-30 09:00:00');

        $this->assertTrue($decl->eligibleForEventRecordedAt('2026-07-30 08:59:59'),
            'before the disable instant: still eligible');
        $this->assertFalse($decl->eligibleForEventRecordedAt('2026-07-30 09:00:00'),
            'at the disable instant: no longer eligible');
        $this->assertFalse($decl->eligibleForEventRecordedAt('2026-07-30 10:00:00'),
            'after the disable instant: no longer eligible');
    }

    public function test_a_disabled_subscriber_still_reports_a_distinct_state(): void
    {
        $this->assertSame(SubscriberRegistry::STATE_DISABLED_FOR_FUTURE,
            $this->stateOf($this->declaration(disabledAt: '2026-07-30 09:00:00')));
    }

    /**
     * The governance window is enforced by ONE function, eligibleForEventRecordedAt(),
     * which fan-out calls for every event. This proves that wiring end to end using
     * the activation boundary — structurally identical to the disable boundary.
     */
    public function test_fanout_creates_no_obligation_outside_the_governance_window(): void
    {
        $eventId = $this->recordEvent(960105, recordedAt: self::BEFORE_ACTIVATION);

        (new EventFanOut())->run();

        $this->assertCount(0, $this->deliveries($eventId),
            'an event outside the governance window gets no delivery row');
        $this->assertNotNull(
            DB::table('platform_events')->where('event_id', $eventId)->value('fanned_out_at'),
            'it is still marked fanned out — the decision was made, not deferred'
        );
    }

    // ═══════════════════════ 5. existing delivery is preserved ══

    public function test_an_existing_delivery_survives_the_subscriber_becoming_ineligible(): void
    {
        $eventId = $this->recordEvent(960106);
        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $before = $this->deliveries($eventId)->first();
        $this->assertSame(DeliveryWorker::STATUS_DELIVERED, $before->status);

        // A retired declaration acquires nothing new...
        $retired = $this->declaration(retiredAt: '2026-07-30 12:00:00');
        $this->assertFalse($retired->acquiresNewObligations());
        $this->assertFalse($retired->eligibleForEventRecordedAt(self::AFTER_ACTIVATION));

        // ...and the historical row is untouched and still inspectable.
        $after = $this->deliveries($eventId)->first();
        $this->assertSame((int) $before->id, (int) $after->id);
        $this->assertSame(DeliveryWorker::STATUS_DELIVERED, $after->status);
        $this->assertNotNull($after->delivered_at);
        $this->assertCount(1, $this->auditRows(), 'the audit evidence remains');
    }

    public function test_a_retired_subscriber_reports_the_retired_state(): void
    {
        $this->assertSame(SubscriberRegistry::STATE_RETIRED,
            $this->stateOf($this->declaration(retiredAt: '2026-07-30 12:00:00')));
    }

    public function test_retirement_cannot_predate_activation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/retiredAt cannot precede activatedAt/');

        $this->declaration(retiredAt: '2020-01-01 00:00:00');
    }

    public function test_governance_timestamps_must_be_explicit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be an explicit timestamp or null/');

        $this->declaration(disabledAt: 'sometime last week');
    }

    // ═══════════════════════ 6. duplicate fan-out ══

    public function test_duplicate_fanout_never_creates_a_second_delivery_identity(): void
    {
        $eventId = $this->recordEvent(960107);

        (new EventFanOut())->run();
        $this->assertCount(1, $this->deliveries($eventId));

        // Force the event back into the fan-out queue and run again. The unique
        // index on (event_id, subscriber_key, subscriber_version) is the guarantee,
        // not a check-then-insert.
        DB::table('platform_events')->where('event_id', $eventId)->update(['fanned_out_at' => null]);
        (new EventFanOut())->run();
        DB::table('platform_events')->where('event_id', $eventId)->update(['fanned_out_at' => null]);
        (new EventFanOut())->run();

        $this->assertCount(1, $this->deliveries($eventId),
            'three fan-out passes, one delivery identity');
    }

    // ═══════════════════════ 7. scheduler pass while paused ══

    public function test_a_scheduled_pass_fans_out_but_does_not_execute_while_paused(): void
    {
        config(['platform_events.subscribers' => ['platform.audit' => false]]);

        $eventId = $this->recordEvent(960108);

        Artisan::call('platform-events:process');
        $output = Artisan::output();

        $this->assertStringContainsString('fanout claimed=1', $output);
        $this->assertStringContainsString('delivered=0', $output);

        $this->assertCount(1, $this->deliveries($eventId), 'fan-out continued');
        $this->assertSame(DeliveryWorker::STATUS_PENDING, $this->deliveries($eventId)->first()->status);
        $this->assertCount(0, $this->auditRows(), 'no execution occurred');
    }

    public function test_health_reports_paused_obligations_as_info_not_critical(): void
    {
        config(['platform_events.subscribers' => ['platform.audit' => false]]);

        $this->recordEvent(960109, recordedAt: '2026-01-01 00:00:00');
        // Age the obligation far beyond the critical threshold.
        (new EventFanOut())->run();
        DB::table('platform_event_deliveries')
            ->whereIn('workspace_id', [$this->ws])
            ->update(['created_at' => now()->subDays(3)]);

        $h = app(\App\Core\PlatformEvents\EventSystemHealth::class)->report();

        $this->assertNotSame(\App\Core\PlatformEvents\EventSystemHealth::CRITICAL, $h['severity'],
            'a deliberately paused subscriber must not raise CRITICAL for its own backlog');
    }

    // ═══════════════════════ 8. cache unavailable ══

    public function test_the_command_fails_closed_when_the_cache_is_unavailable(): void
    {
        $eventId = $this->recordEvent(960110);

        Cache::shouldReceive('lock')->andThrow(new \RuntimeException('redis is gone'));

        Artisan::call('platform-events:process');

        $this->assertCount(0, $this->deliveries($eventId),
            'with no lock obtainable the pass must not run at all');
        $this->assertNull(
            DB::table('platform_events')->where('event_id', $eventId)->value('fanned_out_at'),
            'the event is untouched and will be processed on a later, locked pass'
        );
    }

    // ═══════════════════════ 9. workspace allow-list still enforced ══

    public function test_no_event_is_produced_outside_the_allow_listed_workspace(): void
    {
        $outbox = new Outbox();

        $this->assertTrue($outbox->workspaceAllowed($this->ws));
        $this->assertFalse($outbox->workspaceAllowed($this->wsOutside),
            'the Phase 1C.1 changes must not widen the workspace gate');

        $before = DB::table('platform_events')->where('workspace_id', $this->wsOutside)->count();

        DB::transaction(function () use ($outbox) {
            $outbox->record(new \App\Core\PlatformEvents\Types\DomainOrderCreated(
                workspaceId: $this->wsOutside,
                payload: [
                    'order_id' => 960199, 'currency' => 'USD', 'subtotal_minor' => 100,
                    'item_count' => 1, 'domains' => ['blocked.example'],
                ],
                actorType: \App\Core\PlatformEvents\Types\DomainOrderCreated::ACTOR_SYSTEM,
                actorId: null,
                capabilityKey: 'domain.register',
            ));
        });

        $this->assertSame($before, DB::table('platform_events')->where('workspace_id', $this->wsOutside)->count());
        $this->assertCount(0, $this->deliveries());
    }

    // ═══════════════════════ GUARD EQUIVALENTS ══
    //
    // Phase 1C used temporary in-process instrumentation (a RequestSending listener
    // and a JobQueued listener that called exit()) to prove that order creation
    // touches no registrar, no payment provider and no queue. That instrumentation
    // is activation-only and must not live in production. These tests replace it
    // permanently, and cost no API calls.

    public function test_order_creation_dispatches_no_job_and_calls_no_payment_provider(): void
    {
        $src = file_get_contents(app_path('Services/Domains/DomainCommerceService.php'));

        $start = strpos($src, 'public function createOrder(');
        $this->assertNotFalse($start, 'createOrder must exist');

        // Cut at the method's own closing brace at method indentation. Slicing to
        // the NEXT method declaration instead would swallow that method's docblock
        // — and startCheckout's docblock legitimately mentions Stripe, which made
        // an earlier version of this test fail for the wrong reason.
        $rest = substr($src, $start);
        $end = strpos($rest, "\n    }\n");
        $this->assertNotFalse($end, 'could not find the closing brace at method indentation');
        $body = substr($rest, 0, $end);

        // Sanity-check the slice really is createOrder and stops before checkout.
        $this->assertStringContainsString('CART_EMPTY', $body, 'the slice must be createOrder');
        $this->assertStringNotContainsString('startCheckout', $body, 'the slice must stop before checkout');

        foreach ([
            'dispatch(' => 'a queued job',
            'RegisterDomainJob' => 'domain registration',
            'Stripe' => 'a payment provider',
            'createDomainCheckoutSession' => 'a checkout session',
            'PaymentIntent' => 'a payment intent',
            'NotificationService' => 'a customer notification',
            '->notify(' => 'a customer notification',
        ] as $needle => $what) {
            $this->assertStringNotContainsString($needle, $body,
                "createOrder must not reach {$what} — found '{$needle}'");
        }
    }

    public function test_no_temporary_activation_guard_survives_in_production_code(): void
    {
        foreach (['app', 'config', 'routes', 'bootstrap', 'database'] as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $src = file_get_contents($file->getPathname());

                foreach (['p1c_guard', 'P1C_ORDER_PATH', 'p1c_halt', 'P1C_VIOLATION'] as $marker) {
                    $this->assertStringNotContainsString($marker, $src,
                        'temporary activation instrumentation leaked into ' . $file->getPathname());
                }
            }
        }
    }

    /**
     * The instrumentation used exit() deliberately, because a throw would have been
     * swallowed by NamecheapClient's catch(\Throwable). exit() must never be
     * reachable from ordinary request handling.
     */
    public function test_the_platform_event_chain_contains_no_exit_or_die(): void
    {
        foreach ([
            'Core/PlatformEvents/Outbox.php',
            'Core/PlatformEvents/EventFanOut.php',
            'Core/PlatformEvents/DeliveryWorker.php',
            'Core/PlatformEvents/EventReplay.php',
            'Core/PlatformEvents/EventSystemHealth.php',
            'Core/PlatformEvents/SubscriberRegistry.php',
            'Core/PlatformEvents/SubscriberDeclaration.php',
            'Core/PlatformEvents/Subscribers/AuditSubscriber.php',
        ] as $rel) {
            $src = file_get_contents(app_path($rel));

            $this->assertSame(0, preg_match('/(^|[^_a-zA-Z>$])(exit|die)\s*[(;]/', $src),
                "{$rel} must not contain exit() or die()");
        }
    }

    // ─────────────────────────────────────────── helper ──

    /** Mirror of SubscriberRegistry::state() for a declaration under test. */
    private function stateOf(SubscriberDeclaration $decl): string
    {
        if ($decl->isRetired()) {
            return SubscriberRegistry::STATE_RETIRED;
        }

        if ($decl->disabledForFutureEligibilityAt !== null) {
            return SubscriberRegistry::STATE_DISABLED_FOR_FUTURE;
        }

        return SubscriberRegistry::executionEnabled($decl->key)
            ? SubscriberRegistry::STATE_ACTIVE
            : SubscriberRegistry::STATE_EXECUTION_PAUSED;
    }
}
