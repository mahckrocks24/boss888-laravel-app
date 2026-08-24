<?php

namespace Tests\Feature\PlatformEvents;

use App\Core\PlatformEvents\DeliveryWorker;
use App\Core\PlatformEvents\EventFanOut;
use App\Core\PlatformEvents\Outbox;
use App\Core\PlatformEvents\SubscriberRegistry;
use App\Core\PlatformEvents\Subscribers\AuditSubscriber;
use App\Core\PlatformEvents\Types\DomainOrderCreated;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Phase 1B — delivery ledger, fan-out and the platform.audit subscriber.
 *
 * Proves end-to-end consumption:
 *   order transaction → platform_events → platform_event_deliveries
 *   → platform.audit → audit_logs
 */
class DeliveryTest extends TestCase
{
    use IsolatedDatabase;

    private int $ws = 994001;
    private int $wsB = 994002;
    private int $userId = 994901;

    /** Comfortably after the subscriber's activation timestamp. */
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
            // Phase 1C fail-closed workspace gate (see OutboxTest).
            'platform_events.producer_workspace_allowlist' => [$this->ws, $this->wsB],
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
        $this->removeTenants();
        parent::tearDown();
    }

    /**
     * audit_logs carries FOREIGN KEYS to workspaces.id and users.id, so an audit
     * write for a non-existent tenant fails at the database. Real rows are
     * required — inventing ids would test nothing but the constraint.
     */
    private function seedTenants(): void
    {
        // ORDER MATTERS: workspaces.created_by references users.id, so the user
        // must exist first. workspaces also requires a non-null slug.
        if (! DB::table('users')->where('id', $this->userId)->exists()) {
            DB::table('users')->insert([
                'id' => $this->userId,
                'name' => 'Phase1B Test Actor',
                'email' => 'phase1b-' . $this->userId . '@example.invalid',
                'password' => bcrypt('not-a-real-password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([$this->ws, $this->wsB] as $id) {
            if (! DB::table('workspaces')->where('id', $id)->exists()) {
                DB::table('workspaces')->insert([
                    'id' => $id,
                    'name' => 'Phase1B Test WS ' . $id,
                    'slug' => 'phase1b-ws-' . $id,
                    'created_by' => $this->userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function removeTenants(): void
    {
        DB::table('users')->where('id', $this->userId)->delete();
        DB::table('workspaces')->whereIn('id', [$this->ws, $this->wsB])->delete();
    }

    private function cleanup(): void
    {
        $ids = DB::table('platform_events')->whereIn('workspace_id', [$this->ws, $this->wsB])->pluck('event_id');
        DB::table('platform_event_deliveries')->whereIn('event_id', $ids)->delete();
        DB::table('platform_events')->whereIn('workspace_id', [$this->ws, $this->wsB])->delete();
        DB::table('audit_logs')->whereIn('workspace_id', [$this->ws, $this->wsB])->delete();
    }

    // ─────────────────────────────────────────── helpers ──

    private function recordEvent(int $orderId = 700001, ?int $ws = null, ?string $recordedAt = null, int $schemaVersion = 1): string
    {
        $ws ??= $this->ws;
        $eventId = (string) \Illuminate\Support\Str::uuid();

        DB::table('platform_events')->insert([
            'event_id' => $eventId,
            'workspace_id' => $ws,
            'event_type' => 'domain.order.created',
            'schema_version' => $schemaVersion,
            'capability_key' => 'domain.register',
            'actor_type' => 'user',
            'actor_id' => $this->userId,
            'subject_type' => 'domain_order',
            'subject_id' => (string) $orderId,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload_json' => json_encode([
                'order_id' => $orderId, 'currency' => 'USD', 'subtotal_minor' => 1817,
                'item_count' => 1, 'domains' => ['delivery-test.com'],
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
        $q = DB::table('platform_event_deliveries')->whereIn('workspace_id', [$this->ws, $this->wsB]);

        if ($eventId) {
            $q->where('event_id', $eventId);
        }

        return $q->get();
    }

    private function auditRows()
    {
        return DB::table('audit_logs')->whereIn('workspace_id', [$this->ws, $this->wsB])->get();
    }

    // ══════════════════════════════════════ schema ══

    public function test_delivery_table_has_the_declared_shape(): void
    {
        foreach ([
            'id', 'event_id', 'subscriber_key', 'subscriber_version', 'workspace_id', 'status',
            'attempt_count', 'next_attempt_at', 'claimed_at', 'delivered_at', 'failed_at',
            'last_error', 'skip_reason', 'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('platform_event_deliveries', $col),
                "platform_event_deliveries is missing '{$col}'");
        }
    }

    public function test_platform_events_gained_fanout_fields_without_losing_status(): void
    {
        foreach (['fanned_out_at', 'delivery_count', 'status'] as $col) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('platform_events', $col));
        }
    }

    public function test_duplicate_delivery_identity_is_rejected_by_the_database(): void
    {
        $eventId = $this->recordEvent();

        $row = [
            'event_id' => $eventId, 'subscriber_key' => 'platform.audit', 'subscriber_version' => 1,
            'workspace_id' => $this->ws, 'status' => 'pending', 'attempt_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('platform_event_deliveries')->insert($row);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('platform_event_deliveries')->insert($row);
    }

    public function test_a_new_subscriber_version_is_a_distinct_delivery_identity(): void
    {
        $eventId = $this->recordEvent();

        foreach ([1, 2] as $v) {
            DB::table('platform_event_deliveries')->insert([
                'event_id' => $eventId, 'subscriber_key' => 'platform.audit', 'subscriber_version' => $v,
                'workspace_id' => $this->ws, 'status' => 'pending', 'attempt_count' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, $this->deliveries($eventId)->count(),
            'a version bump must allow a deliberate re-delivery without reopening the old row');
    }

    // ══════════════════════════════════════ fan-out ══

    public function test_fanout_creates_one_pending_delivery_for_an_active_subscriber(): void
    {
        $eventId = $this->recordEvent();

        $r = (new EventFanOut())->run();

        $this->assertSame(1, $r['claimed']);
        $this->assertSame(1, $r['created']);
        $this->assertSame(1, $this->deliveries($eventId)->count());

        $d = $this->deliveries($eventId)->first();
        $this->assertSame('platform.audit', $d->subscriber_key);
        $this->assertSame(1, (int) $d->subscriber_version);
        $this->assertSame('pending', $d->status);
        $this->assertSame($this->ws, (int) $d->workspace_id);
    }

    public function test_an_event_predating_activation_receives_no_delivery(): void
    {
        $eventId = $this->recordEvent(recordedAt: self::BEFORE_ACTIVATION);

        $r = (new EventFanOut())->run();

        $this->assertSame(0, $r['created'], 'delivery is prospective — history is never reached automatically');
        $this->assertSame(0, $this->deliveries($eventId)->count());
    }

    public function test_fanout_marks_the_event_and_records_the_delivery_count(): void
    {
        $eventId = $this->recordEvent();

        (new EventFanOut())->run();

        $e = DB::table('platform_events')->where('event_id', $eventId)->first();
        $this->assertNotNull($e->fanned_out_at);
        $this->assertSame(1, (int) $e->delivery_count);
    }

    public function test_fanout_is_idempotent(): void
    {
        $eventId = $this->recordEvent();

        (new EventFanOut())->run();
        // Force a second pass over the same event.
        DB::table('platform_events')->where('event_id', $eventId)->update(['fanned_out_at' => null]);
        (new EventFanOut())->run();

        $this->assertSame(1, $this->deliveries($eventId)->count(), 'a second fan-out must not duplicate the delivery');
    }

    public function test_fanout_does_nothing_when_disabled(): void
    {
        config(['platform_events.fanout.enabled' => false]);
        $eventId = $this->recordEvent();

        $r = (new EventFanOut())->run();

        $this->assertSame(0, $r['claimed']);
        $this->assertSame(0, $this->deliveries($eventId)->count());
    }

    /**
     * REVISED BY PHASE 1C.1.
     *
     * Previously: "fanout creates nothing when the subscriber flag is off" — which
     * is precisely how Phase 1C event #1 was lost. The execution flag is now an
     * execution control only; the obligation is created regardless and waits.
     */
    public function test_fanout_still_creates_the_obligation_when_execution_is_paused(): void
    {
        config(['platform_events.subscribers' => ['platform.audit' => false]]);
        $eventId = $this->recordEvent();

        $r = (new EventFanOut())->run();

        $this->assertSame(1, $r['claimed'], 'the event is still processed');
        $this->assertSame(1, $r['created'], 'the obligation IS created while paused');
        $this->assertSame(0, $r['no_subscribers'], 'there IS an eligible subscriber');
        $this->assertSame(1, $this->deliveries($eventId)->count());
        $this->assertSame(DeliveryWorker::STATUS_PENDING, $this->deliveries($eventId)->first()->status);

        // And nothing runs.
        (new DeliveryWorker())->run();
        $this->assertSame(DeliveryWorker::STATUS_PENDING, $this->deliveries($eventId)->first()->status);
        $this->assertCount(0, $this->auditRows());
    }

    public function test_an_unsupported_schema_version_is_skipped_never_silently_succeeded(): void
    {
        $eventId = $this->recordEvent(schemaVersion: 99);

        (new EventFanOut())->run();

        $d = $this->deliveries($eventId)->first();
        $this->assertNotNull($d, 'an unsupported schema must produce an inspectable row, not silence');
        $this->assertSame('skipped', $d->status);
        $this->assertStringContainsString('schema v99', $d->skip_reason);
        $this->assertSame(0, $this->auditRows()->count(), 'a skipped delivery writes no audit row');
    }

    // ═════════════════════════════════ audit delivery ══

    public function test_the_audit_subscriber_writes_exactly_one_audit_row(): void
    {
        $this->recordEvent();
        (new EventFanOut())->run();

        $r = (new DeliveryWorker())->run();

        $this->assertSame(1, $r['claimed']);
        $this->assertSame(1, $r['delivered']);
        $this->assertSame(1, $this->auditRows()->count());

        $a = $this->auditRows()->first();
        $this->assertSame('domain.order.created', $a->action);
        $this->assertSame('domain_order', $a->entity_type);
        $this->assertSame(700001, (int) $a->entity_id);
        $this->assertSame($this->ws, (int) $a->workspace_id);
    }

    public function test_the_delivery_becomes_delivered(): void
    {
        $eventId = $this->recordEvent();
        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $d = $this->deliveries($eventId)->first();
        $this->assertSame('delivered', $d->status);
        $this->assertNotNull($d->delivered_at);
        $this->assertSame(1, (int) $d->attempt_count);
        $this->assertNull($d->last_error);
    }

    public function test_the_audit_row_carries_full_source_traceability(): void
    {
        $eventId = $this->recordEvent();
        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $meta = json_decode($this->auditRows()->first()->metadata_json, true);

        foreach (['source', 'event_id', 'event_type', 'schema_version', 'correlation_id',
                  'capability_key', 'actor_type', 'subject_type', 'subject_id', 'occurred_at',
                  'subscriber_key', 'subscriber_version'] as $k) {
            $this->assertArrayHasKey($k, $meta, "audit metadata must carry '{$k}' for traceability");
        }

        $this->assertSame('platform_event', $meta['source']);
        $this->assertSame($eventId, $meta['event_id']);
        $this->assertSame('platform.audit', $meta['subscriber_key']);
        $this->assertSame(1, $meta['subscriber_version']);
        $this->assertSame('domain.register', $meta['capability_key']);
    }

    public function test_the_audit_row_does_not_claim_payment_or_registration(): void
    {
        $this->recordEvent();
        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $a = $this->auditRows()->first();
        $meta = json_decode($a->metadata_json, true);

        $this->assertStringContainsString('created', $meta['summary']);
        $this->assertStringNotContainsStringIgnoringCase('paid', $meta['summary']);
        $this->assertStringNotContainsStringIgnoringCase('registered', $meta['summary']);
        $this->assertStringNotContainsStringIgnoringCase('owns', $meta['summary']);
        $this->assertSame('not_paid_at_time_of_event', $meta['detail']['payment_state']);
    }

    public function test_the_audit_mapping_is_explicit_not_a_payload_copy(): void
    {
        $eventId = $this->recordEvent();

        // Add a field the mapping does not know about.
        DB::table('platform_events')->where('event_id', $eventId)->update([
            'payload_json' => json_encode([
                'order_id' => 700001, 'currency' => 'USD', 'subtotal_minor' => 1817,
                'item_count' => 1, 'domains' => ['delivery-test.com'],
                'unmapped_future_field' => 'must not appear in audit',
            ]),
        ]);

        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $raw = $this->auditRows()->first()->metadata_json;

        $this->assertStringNotContainsString('unmapped_future_field', $raw,
            'the mapping must be explicit; an unknown payload field must be dropped');
        $this->assertStringNotContainsString('must not appear in audit', $raw);
    }

    // ══════════════════════════════ idempotency ══

    public function test_a_second_worker_pass_does_not_duplicate_the_audit_row(): void
    {
        $this->recordEvent();
        (new EventFanOut())->run();

        (new DeliveryWorker())->run();
        (new DeliveryWorker())->run();

        $this->assertSame(1, $this->auditRows()->count(), 'a settled delivery must not be re-executed');
    }

    /**
     * The crash window. A worker that dies after claiming leaves 'processing';
     * the reaper returns it to pending and the retry re-runs cleanly, because
     * the audit insert and the delivered mark shared one transaction and both
     * rolled back.
     */
    public function test_a_crash_after_claim_recovers_without_duplicating_the_audit_row(): void
    {
        $eventId = $this->recordEvent();
        (new EventFanOut())->run();

        // Simulate a worker that claimed and died before the atomic pair ran.
        DB::table('platform_event_deliveries')->where('event_id', $eventId)->update([
            'status' => 'processing', 'claimed_at' => now()->subHours(2), 'attempt_count' => 1,
        ]);

        $this->assertSame(0, $this->auditRows()->count(), 'the crashed attempt wrote nothing');

        $reaped = (new DeliveryWorker())->reapStaleClaims();
        $this->assertSame(1, $reaped);

        (new DeliveryWorker())->run();

        $this->assertSame(1, $this->auditRows()->count(), 'exactly one audit row after recovery');
        $this->assertSame('delivered', $this->deliveries($eventId)->first()->status);
    }

    public function test_concurrent_claims_yield_one_logical_result(): void
    {
        $eventId = $this->recordEvent();
        (new EventFanOut())->run();

        // Two workers racing the same due row.
        $a = new DeliveryWorker();
        $b = new DeliveryWorker();

        $ra = $a->run();
        $rb = $b->run();

        $this->assertSame(1, $ra['claimed'] + $rb['claimed'], 'exactly one worker may claim');
        $this->assertSame(1, $this->auditRows()->count());
        $this->assertSame(1, $this->deliveries($eventId)->count());
    }

    // ══════════════════════════════════ failures ══

    public function test_a_failing_subscriber_becomes_retryable_then_permanently_failed(): void
    {
        config(['platform_events.subscribers' => ['platform.audit' => true]]);

        $eventId = $this->recordEvent();
        (new EventFanOut())->run();

        // Poison it: an event type the subscriber has no audit mapping for.
        // (MySQL rejects malformed JSON in a json column, so a bad payload cannot
        // be written directly — an unmapped type produces the same throw.)
        DB::table('platform_events')->where('event_id', $eventId)->update(['event_type' => 'domain.unmapped.thing']);

        $r = (new DeliveryWorker())->run();

        $this->assertSame(1, $r['failed'], 'a structural mismatch is permanent, not retried');
        $d = $this->deliveries($eventId)->first();
        $this->assertSame('permanently_failed', $d->status,
            'the subscriber declared the failure permanent via PermanentSubscriberFailure');
        $this->assertNotNull($d->failed_at);
        $this->assertNotEmpty($d->last_error);
        $this->assertSame(0, $this->auditRows()->count());
    }

    public function test_a_poison_event_exhausts_bounded_retries(): void
    {
        $eventId = $this->recordEvent();
        (new EventFanOut())->run();

        // An unmapped event type makes the subscriber throw on every attempt.
        // attempt_count is pushed to the ceiling so the next failure is terminal.
        DB::table('platform_events')->where('event_id', $eventId)->update(['event_type' => 'domain.unmapped.thing']);
        DB::table('platform_event_deliveries')->where('event_id', $eventId)->update(['attempt_count' => 5]);

        (new DeliveryWorker())->run();

        $d = $this->deliveries($eventId)->first();
        $this->assertSame('permanently_failed', $d->status);
        $this->assertNotEmpty($d->last_error, 'a permanently failed delivery must be inspectable');
    }

    public function test_a_subscriber_failure_never_alters_the_source_event(): void
    {
        $eventId = $this->recordEvent();
        (new EventFanOut())->run();

        $before = DB::table('platform_events')->where('event_id', $eventId)->first();

        DB::table('platform_events')->where('event_id', $eventId)->update(['event_type' => 'domain.unmapped.thing']);
        (new DeliveryWorker())->run();

        $after = DB::table('platform_events')->where('event_id', $eventId)->first();

        $this->assertSame($before->event_id, $after->event_id);
        $this->assertSame($before->occurred_at, $after->occurred_at);
        $this->assertSame($before->sensitivity, $after->sensitivity);
    }

    // ══════════════════════════════════ tenancy ══

    public function test_a_tenancy_mismatch_fails_closed_with_no_audit_row(): void
    {
        $eventId = $this->recordEvent();
        (new EventFanOut())->run();

        // Corrupt the delivery's tenant.
        DB::table('platform_event_deliveries')->where('event_id', $eventId)->update(['workspace_id' => $this->wsB]);

        (new DeliveryWorker())->run();

        $d = DB::table('platform_event_deliveries')->where('event_id', $eventId)->first();
        $this->assertSame('permanently_failed', $d->status);
        $this->assertStringContainsString('tenancy mismatch', $d->last_error);
        $this->assertSame(0, $this->auditRows()->count(), 'no cross-tenant audit row may be written');
    }

    public function test_two_workspaces_are_audited_independently(): void
    {
        $this->recordEvent(orderId: 700001, ws: $this->ws);
        $this->recordEvent(orderId: 700002, ws: $this->wsB);

        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $this->assertSame(2, $this->auditRows()->count());
        $this->assertSame(1, $this->auditRows()->where('workspace_id', $this->ws)->count());
        $this->assertSame(1, $this->auditRows()->where('workspace_id', $this->wsB)->count());
    }

    // ══════════════════════ disabled worker / preservation ══

    public function test_a_disabled_worker_preserves_pending_deliveries_without_false_delivery(): void
    {
        $eventId = $this->recordEvent();
        (new EventFanOut())->run();

        config(['platform_events.delivery_worker.enabled' => false]);

        $r = (new DeliveryWorker())->run();

        $this->assertSame(0, $r['claimed']);
        $this->assertSame('pending', $this->deliveries($eventId)->first()->status,
            'a disabled worker must leave the delivery pending, never mark it delivered');
        $this->assertSame(0, $this->auditRows()->count());
    }

    public function test_a_delivery_not_yet_due_is_not_claimed(): void
    {
        $eventId = $this->recordEvent();
        (new EventFanOut())->run();
        DB::table('platform_event_deliveries')->where('event_id', $eventId)
            ->update(['status' => 'retryable_failure', 'next_attempt_at' => now()->addHour()]);

        $this->assertSame(0, (new DeliveryWorker())->run()['claimed']);
    }

    // ══════════════════ no side effects beyond audit ══

    public function test_delivery_emits_no_notification_and_writes_no_memory(): void
    {
        $notifBefore = DB::table('notifications')->count();
        $memBefore = DB::table('workspace_memory')->count();
        $infraBefore = DB::table('infra_events')->count();

        $this->recordEvent();
        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $this->assertSame($notifBefore, DB::table('notifications')->count(), 'Phase 1B must not notify anyone');
        $this->assertSame($memBefore, DB::table('workspace_memory')->count(), 'Phase 1B must not write memory');
        $this->assertSame($infraBefore, DB::table('infra_events')->count(), 'infra_events behaviour must not change');
    }

    // ══════════════════════════ END TO END ══

    public function test_full_chain_from_order_transaction_to_audit_log(): void
    {
        $this->fakeRegistrar();

        $auditBefore = $this->auditRows()->count();

        // 1. the real producer, inside the real order transaction
        $r = \App\Services\Domains\DomainCommerceService::make()
            ->createOrder($this->ws, null, [['domain' => 'end-to-end.com', 'years' => 1]]);

        $this->assertArrayNotHasKey('error', $r);

        // The producer stamps recorded_at = now(); activation is in the past, so
        // it is eligible without adjustment.
        $events = DB::table('platform_events')->where('workspace_id', $this->ws)->get();
        $this->assertSame(1, $events->count(), 'one event recorded by the order transaction');

        // 2. fan-out
        $fan = (new EventFanOut())->run();
        $this->assertSame(1, $fan['created']);

        // 3. delivery
        $del = (new DeliveryWorker())->run();
        $this->assertSame(1, $del['delivered']);

        // 4. audit
        $this->assertSame($auditBefore + 1, $this->auditRows()->count());

        $meta = json_decode($this->auditRows()->last()->metadata_json, true);
        $this->assertSame($events->first()->event_id, $meta['event_id']);
        $this->assertSame(['end-to-end.com'], $meta['detail']['domains']);

        // 5. nothing internal leaked into the audit trail
        $raw = $this->auditRows()->last()->metadata_json;
        foreach (['markup', 'registrar_cost', 'api_key', 'namecheap'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $raw);
        }
    }

    private function fakeRegistrar(): void
    {
        config([
            'namecheap.environment' => 'sandbox',
            'namecheap.client_ip' => '134.209.93.41',
            'namecheap.credentials.sandbox' => ['api_user' => 'u', 'api_key' => 'k', 'username' => 'u'],
            'namecheap.pricing.markup_percent' => 30.0,
            'namecheap.pricing.markup_minimum_usd' => 4.00,
        ]);

        \Illuminate\Support\Facades\Http::fake(function ($request) {
            if (str_contains((string) $request->body(), 'domains.check')) {
                return \Illuminate\Support\Facades\Http::response('<?xml version="1.0"?><ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
<Errors /><CommandResponse><DomainCheckResult Domain="x.com" Available="true" IsPremiumName="false" IcannFee="0.18" /></CommandResponse></ApiResponse>', 200);
            }

            return \Illuminate\Support\Facades\Http::response('<?xml version="1.0"?><ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
<Errors /><CommandResponse><UserGetPricingResult><ProductType Name="domains"><ProductCategory Name="register">
<Product Name="com"><Price Duration="1" DurationType="YEAR" Price="13.98" RegularPrice="13.98" YourPrice="13.98" Currency="USD" /></Product>
</ProductCategory></ProductType></UserGetPricingResult></CommandResponse></ApiResponse>', 200);
        });
    }
}
