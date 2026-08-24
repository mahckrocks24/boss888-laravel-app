<?php

namespace Tests\Feature\PlatformEvents;

use App\Core\PlatformEvents\Outbox;
use App\Core\PlatformEvents\OutboxDispatcher;
use App\Core\PlatformEvents\PlatformEvent;
use App\Core\PlatformEvents\Types\DomainOrderCreated;
use App\Models\DomainOrder;
use App\Models\DomainOrderItem;
use App\Services\Domains\DomainCommerceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Phase 1A — transactional outbox foundation.
 *
 * Proves production and recoverability ONLY. There are no subscribers, and no
 * test here claims audit, notification, memory or analytics integration exists.
 */
class OutboxTest extends TestCase
{
    use IsolatedDatabase;

    private int $ws = 993001;
    private int $wsB = 993002;

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 1E.1: never run against a shared database.
        $this->assertIsolatedDatabase();

        config([
            'platform_events.enabled' => true,
            // Set the ARRAY: a dotted config path cannot address a dotted key.
            'platform_events.producers' => ['domain.order.created' => true],
            // Phase 1C fail-closed workspace gate: an empty allow-list permits
            // nothing, so the workspaces under test must be named explicitly.
            'platform_events.producer_workspace_allowlist' => [$this->ws, $this->wsB],
        ]);

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        DB::table('platform_events')->whereIn('workspace_id', [$this->ws, $this->wsB])->delete();
        DomainOrderItem::whereIn('workspace_id', [$this->ws, $this->wsB])->delete();
        DomainOrder::whereIn('workspace_id', [$this->ws, $this->wsB])->delete();
    }

    private function event(array $overrides = []): DomainOrderCreated
    {
        return new DomainOrderCreated(
            workspaceId: $overrides['ws'] ?? $this->ws,
            payload: $overrides['payload'] ?? [
                'order_id' => $overrides['order_id'] ?? 900001,
                'currency' => 'USD',
                'subtotal_minor' => 1817,
                'item_count' => 1,
                'domains' => ['outbox-test.com'],
            ],
            actorType: PlatformEvent::ACTOR_SYSTEM,
            capabilityKey: 'domain.register',
        );
    }

    private function rows(): \Illuminate\Support\Collection
    {
        return DB::table('platform_events')->whereIn('workspace_id', [$this->ws, $this->wsB])->get();
    }

    // ══════════════════════════════════════════════════ schema ══

    public function test_outbox_table_has_the_declared_shape(): void
    {
        foreach ([
            'id', 'event_id', 'workspace_id', 'event_type', 'schema_version', 'capability_key',
            'actor_type', 'actor_id', 'subject_type', 'subject_id', 'correlation_id', 'causation_id',
            'payload_json', 'sensitivity', 'status', 'occurred_at', 'recorded_at',
            'next_attempt_at', 'locked_at', 'dispatched_at', 'attempt_count', 'last_error',
        ] as $col) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('platform_events', $col),
                "platform_events is missing column '{$col}'"
            );
        }
    }

    public function test_event_id_is_globally_unique(): void
    {
        DB::table('platform_events')->insert($this->rawRow('dup-uuid-0000-0000-000000000001'));

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('platform_events')->insert($this->rawRow('dup-uuid-0000-0000-000000000001'));
    }

    private function rawRow(string $eventId): array
    {
        return [
            'event_id' => $eventId, 'workspace_id' => $this->ws,
            'event_type' => 'test.event', 'schema_version' => 1,
            'actor_type' => 'system', 'subject_type' => 'test', 'subject_id' => '1',
            'correlation_id' => 'aaaaaaaa-0000-0000-0000-000000000001',
            'payload_json' => '{}', 'sensitivity' => 'internal', 'status' => 'pending',
            'occurred_at' => now(), 'recorded_at' => now(), 'attempt_count' => 0,
        ];
    }

    // ══════════════════════════════════════ typed validation ══

    public function test_missing_required_payload_field_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing required payload field/');

        new DomainOrderCreated(workspaceId: $this->ws, payload: ['order_id' => 1]);
    }

    public function test_workspace_id_is_mandatory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/workspace_id is mandatory/');

        $this->event(['ws' => 0]);
    }

    public function test_payload_field_types_are_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DomainOrderCreated(workspaceId: $this->ws, payload: [
            'order_id' => 'not-an-int', 'currency' => 'USD',
            'subtotal_minor' => 1, 'item_count' => 1, 'domains' => ['a.com'],
        ]);
    }

    public function test_item_count_must_match_the_domain_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DomainOrderCreated(workspaceId: $this->ws, payload: [
            'order_id' => 1, 'currency' => 'USD', 'subtotal_minor' => 1,
            'item_count' => 3, 'domains' => ['a.com'],
        ]);
    }

    public function test_internal_commercial_data_cannot_be_published(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/internal commercial data/');

        new DomainOrderCreated(workspaceId: $this->ws, payload: [
            'order_id' => 1, 'currency' => 'USD', 'subtotal_minor' => 1,
            'item_count' => 1, 'domains' => ['a.com'], 'markup_minor' => 419,
        ]);
    }

    public function test_unknown_actor_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DomainOrderCreated(
            workspaceId: $this->ws,
            payload: ['order_id' => 1, 'currency' => 'USD', 'subtotal_minor' => 1, 'item_count' => 1, 'domains' => ['a.com']],
            actorType: 'robot'
        );
    }

    // ══════════════════════════════════════════ secrets ══

    public function test_blocked_payload_keys_are_refused(): void
    {
        // A typed event cannot carry these, so exercise the Outbox guard with a
        // synthetic event whose payload passes type validation.
        $evt = new class($this->ws) extends PlatformEvent {
            public function __construct(int $ws)
            {
                parent::__construct($ws, [
                    'order_id' => 1,
                    'provider' => ['api_key' => 'sk_live_should_never_be_recorded'],
                ]);
            }
            public static function type(): string { return 'test.secret.event'; }
            public static function schemaVersion(): int { return 1; }
            public static function requiredPayloadKeys(): array { return ['order_id']; }
            public function subjectType(): string { return 'test'; }
            public function subjectId(): string { return '1'; }
        };

        config(['platform_events.producers' => ['test.secret.event' => true]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/blocked list/');

        DB::transaction(fn () => app(Outbox::class)->record($evt));
    }

    // ══════════════════════════════════ transaction rule ══

    public function test_record_refuses_to_run_outside_a_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be called inside the transaction/');

        app(Outbox::class)->record($this->event());
    }

    public function test_event_commits_with_the_business_state(): void
    {
        DB::transaction(function () {
            DomainOrder::create([
                'workspace_id' => $this->ws, 'status' => 'pending', 'currency' => 'USD',
                'subtotal_minor' => 1817, 'total_minor' => 1817, 'cost_total_minor' => 1398,
            ]);
            app(Outbox::class)->record($this->event());
        });

        $this->assertSame(1, $this->rows()->count());
        $this->assertSame(1, DomainOrder::where('workspace_id', $this->ws)->count());
    }

    /** The core invariant: a rolled-back business transaction leaves NO event. */
    public function test_rolled_back_transaction_leaves_no_event(): void
    {
        try {
            DB::transaction(function () {
                DomainOrder::create([
                    'workspace_id' => $this->ws, 'status' => 'pending', 'currency' => 'USD',
                    'subtotal_minor' => 1817, 'total_minor' => 1817, 'cost_total_minor' => 1398,
                ]);
                app(Outbox::class)->record($this->event());

                throw new RuntimeException('business failure after the event was recorded');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $this->rows()->count(), 'the event must not survive a rolled-back transaction');
        $this->assertSame(0, DomainOrder::where('workspace_id', $this->ws)->count());
    }

    /** Malformed payload must fail BEFORE anything commits. */
    public function test_malformed_payload_prevents_the_business_commit(): void
    {
        try {
            DB::transaction(function () {
                DomainOrder::create([
                    'workspace_id' => $this->ws, 'status' => 'pending', 'currency' => 'USD',
                    'subtotal_minor' => 1817, 'total_minor' => 1817, 'cost_total_minor' => 1398,
                ]);
                app(Outbox::class)->record(new DomainOrderCreated(workspaceId: $this->ws, payload: ['order_id' => 1]));
            });
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, DomainOrder::where('workspace_id', $this->ws)->count(),
            'a malformed event must abort the business transaction, not be dropped silently');
        $this->assertSame(0, $this->rows()->count());
    }

    // ══════════════════════════════════════ idempotency ══

    public function test_duplicate_producer_invocation_records_one_logical_event(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        $this->assertSame(1, $this->rows()->count(), 'the deterministic event_id must suppress the duplicate');
    }

    public function test_duplicate_suppression_does_not_break_the_callers_transaction(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        DB::transaction(function () {
            DomainOrder::create([
                'workspace_id' => $this->ws, 'status' => 'pending', 'currency' => 'USD',
                'subtotal_minor' => 1, 'total_minor' => 1, 'cost_total_minor' => 1,
            ]);
            app(Outbox::class)->record($this->event());   // duplicate
        });

        $this->assertSame(1, DomainOrder::where('workspace_id', $this->ws)->count(),
            'a duplicate event must never roll back the business transaction');
        $this->assertSame(1, $this->rows()->count());
    }

    public function test_event_id_is_deterministic_and_differs_per_subject(): void
    {
        $a = $this->event(['order_id' => 111]);
        $b = $this->event(['order_id' => 111]);
        $c = $this->event(['order_id' => 222]);

        $this->assertSame($a->eventId(), $b->eventId());
        $this->assertNotSame($a->eventId(), $c->eventId());
    }

    // ═════════════════════════════════════════ tenancy ══

    public function test_the_same_subject_in_two_workspaces_are_distinct_events(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));
        DB::transaction(fn () => app(Outbox::class)->record($this->event(['ws' => $this->wsB])));

        $this->assertSame(2, $this->rows()->count());
        $this->assertSame(1, $this->rows()->where('workspace_id', $this->ws)->count());
        $this->assertSame(1, $this->rows()->where('workspace_id', $this->wsB)->count());
    }

    public function test_workspace_id_is_recorded_on_every_row(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        $this->assertSame($this->ws, (int) $this->rows()->first()->workspace_id);
    }

    // ═══════════════════════════════════ feature flag ══

    public function test_producer_is_a_no_op_when_the_producer_flag_is_off(): void
    {
        config(['platform_events.producers' => ['domain.order.created' => false]]);

        $id = DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        $this->assertNull($id);
        $this->assertSame(0, $this->rows()->count());
    }

    public function test_producer_is_a_no_op_when_the_master_switch_is_off(): void
    {
        config(['platform_events.enabled' => false]);

        $id = DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        $this->assertNull($id);
        $this->assertSame(0, $this->rows()->count());
    }

    // ═══════════════════════════════════ dispatcher ══

    public function test_an_event_with_no_subscribers_is_marked_no_subscribers_not_dispatched(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        $result = (new OutboxDispatcher())->run();

        $this->assertSame(1, $result['claimed']);
        $this->assertSame(1, $result['no_subscribers']);
        $this->assertSame(0, $result['dispatched']);

        $row = $this->rows()->first();
        $this->assertSame(Outbox::STATUS_NO_SUBSCRIBERS, $row->status);
        $this->assertNull($row->dispatched_at, 'nothing consumed it, so it was never dispatched');
    }

    public function test_a_registered_subscriber_marks_the_event_dispatched(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        $seen = [];
        $d = new OutboxDispatcher();
        $d->subscribe('domain.order.created', function ($row, $payload) use (&$seen) {
            $seen[] = $payload['order_id'];
        });

        $result = $d->run();

        $this->assertSame(1, $result['dispatched']);
        $this->assertSame([900001], $seen);
        $this->assertSame(Outbox::STATUS_DISPATCHED, $this->rows()->first()->status);
        $this->assertNotNull($this->rows()->first()->dispatched_at);
    }

    public function test_a_failing_subscriber_retries_with_backoff(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        $d = new OutboxDispatcher();
        $d->subscribe('domain.order.created', fn () => throw new RuntimeException('subscriber exploded'));

        $result = $d->run();

        $this->assertSame(1, $result['retried']);

        $row = $this->rows()->first();
        $this->assertSame(Outbox::STATUS_PENDING, $row->status, 'a transient failure returns to pending');
        $this->assertSame(1, (int) $row->attempt_count);
        $this->assertNotNull($row->next_attempt_at);
        $this->assertStringContainsString('subscriber exploded', $row->last_error);
    }

    public function test_a_persistently_failing_subscriber_eventually_fails_permanently(): void
    {
        config(['platform_events.dispatcher.max_attempts' => 2, 'platform_events.dispatcher.backoff' => [0]]);

        DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        $d = new OutboxDispatcher();
        $d->subscribe('domain.order.created', fn () => throw new RuntimeException('always fails'));

        $d->run();
        $d->run();

        $this->assertSame(Outbox::STATUS_FAILED, $this->rows()->first()->status);
    }

    public function test_a_subscriber_failure_cannot_alter_the_business_record(): void
    {
        DB::transaction(function () {
            DomainOrder::create([
                'workspace_id' => $this->ws, 'status' => 'pending', 'currency' => 'USD',
                'subtotal_minor' => 1817, 'total_minor' => 1817, 'cost_total_minor' => 1398,
            ]);
            app(Outbox::class)->record($this->event());
        });

        $d = new OutboxDispatcher();
        $d->subscribe('domain.order.created', fn () => throw new RuntimeException('boom'));
        $d->run();

        $this->assertSame(1, DomainOrder::where('workspace_id', $this->ws)->count(),
            'the committed order must survive any subscriber failure');
    }

    public function test_a_claimed_row_is_not_claimed_again_by_a_second_pass(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        $first = (new OutboxDispatcher())->run();
        $second = (new OutboxDispatcher())->run();

        $this->assertSame(1, $first['claimed']);
        $this->assertSame(0, $second['claimed'], 'a settled row must not be picked up twice');
    }

    public function test_a_crashed_worker_leaves_the_event_recoverable(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));

        // Simulate a worker that claimed the row and died before settling it.
        DB::table('platform_events')->update([
            'status' => Outbox::STATUS_DISPATCHING,
            'locked_at' => now()->subHours(2),
        ]);

        $reaped = (new OutboxDispatcher())->reapStaleLocks();

        $this->assertSame(1, $reaped);
        $this->assertSame(Outbox::STATUS_PENDING, $this->rows()->first()->status);
    }

    public function test_backoff_ladder_grows_and_then_plateaus(): void
    {
        config(['platform_events.dispatcher.backoff' => [30, 120, 600]]);
        $d = new OutboxDispatcher();

        $this->assertSame(30, $d->backoffFor(1));
        $this->assertSame(120, $d->backoffFor(2));
        $this->assertSame(600, $d->backoffFor(3));
        $this->assertSame(600, $d->backoffFor(9));
    }

    public function test_an_event_not_yet_due_is_not_claimed(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event()));
        DB::table('platform_events')->update(['next_attempt_at' => now()->addHour()]);

        $this->assertSame(0, (new OutboxDispatcher())->run()['claimed']);
    }

    // ════════════════════════ the real producer, end to end ══

    public function test_creating_an_order_records_exactly_one_event_and_changes_nothing_else(): void
    {
        $this->fakeRegistrar();

        $before = DomainOrder::count();

        $r = DomainCommerceService::make()->createOrder($this->ws, null, [['domain' => 'shadow-producer.com', 'years' => 1]]);

        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame($before + 1, DomainOrder::count());

        $rows = $this->rows();
        $this->assertSame(1, $rows->count(), 'exactly one event per order');

        $row = $rows->first();
        $this->assertSame('domain.order.created', $row->event_type);
        $this->assertSame($this->ws, (int) $row->workspace_id);
        $this->assertSame('domain_order', $row->subject_type);
        $this->assertSame((string) $r['order']['id'], $row->subject_id);
        $this->assertSame('domain.register', $row->capability_key);
        $this->assertSame(Outbox::STATUS_PENDING, $row->status);

        $payload = json_decode($row->payload_json, true);
        $this->assertSame(['shadow-producer.com'], $payload['domains']);
        $this->assertSame('USD', $payload['currency']);
        $this->assertArrayNotHasKey('markup_minor', $payload, 'internal commercial data must not be published');
        $this->assertArrayNotHasKey('registrar_cost_minor', $payload);
    }

    public function test_the_order_response_is_identical_with_the_producer_off(): void
    {
        $this->fakeRegistrar();

        config(['platform_events.producers' => ['domain.order.created' => true]]);
        $on = DomainCommerceService::make()->createOrder($this->ws, null, [['domain' => 'flag-on.com', 'years' => 1]]);

        config(['platform_events.producers' => ['domain.order.created' => false]]);
        $off = DomainCommerceService::make()->createOrder($this->wsB, null, [['domain' => 'flag-off.com', 'years' => 1]]);

        // Same shape, same keys, same statuses — the flag changes nothing the
        // customer or the caller can observe.
        $this->assertSame(array_keys($on['order']), array_keys($off['order']));
        $this->assertSame($on['order']['status'], $off['order']['status']);
        $this->assertSame($on['order']['total'], $off['order']['total']);

        $this->assertSame(1, $this->rows()->where('workspace_id', $this->ws)->count());
        $this->assertSame(0, $this->rows()->where('workspace_id', $this->wsB)->count());
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

        Http::fake(function ($request) {
            $body = (string) $request->body();

            if (str_contains($body, 'domains.check')) {
                return Http::response('<?xml version="1.0"?><ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
<Errors /><CommandResponse><DomainCheckResult Domain="x.com" Available="true" IsPremiumName="false" IcannFee="0.18" /></CommandResponse></ApiResponse>', 200);
            }

            return Http::response('<?xml version="1.0"?><ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
<Errors /><CommandResponse><UserGetPricingResult><ProductType Name="domains"><ProductCategory Name="register">
<Product Name="com"><Price Duration="1" DurationType="YEAR" Price="13.98" RegularPrice="13.98" YourPrice="13.98" Currency="USD" /></Product>
</ProductCategory></ProductType></UserGetPricingResult></CommandResponse></ApiResponse>', 200);
        });
    }
}
