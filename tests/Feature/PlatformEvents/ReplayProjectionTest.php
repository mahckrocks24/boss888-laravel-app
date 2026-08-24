<?php

namespace Tests\Feature\PlatformEvents;

use App\Core\PlatformEvents\DeliveryProjection;
use App\Core\PlatformEvents\DeliveryWorker;
use App\Core\PlatformEvents\EventFanOut;
use App\Core\PlatformEvents\EventReplay;
use App\Core\PlatformEvents\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Phase 1D — the delivery_count projection must always be DERIVED from the ledger.
 *
 * Two drift paths existed:
 *
 *  - EventReplay created a valid delivery obligation and never touched the
 *    projection. That is how Phase 1C event #1 ended up delivered with a projected
 *    count of 0.
 *  - EventFanOut wrote `created + skipped`, i.e. what THAT pass inserted. Re-running
 *    fan-out over an event whose rows already existed inserted nothing and rewrote a
 *    correct count back to 0.
 *
 * The ledger (platform_event_deliveries) remains authoritative throughout. The
 * projection is a cache of it, and every writer now recomputes rather than guesses.
 */
class ReplayProjectionTest extends TestCase
{
    use IsolatedDatabase;

    private int $ws = 998001;
    private int $userId = 998901;

    private const RECORDED_AT = '2026-07-30 12:00:00';
    private const FROM = '2026-07-30 11:59:00';
    private const TO = '2026-07-30 12:01:00';

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
        DB::table('workspaces')->where('id', $this->ws)->delete();
        parent::tearDown();
    }

    private function seedTenants(): void
    {
        if (! DB::table('users')->where('id', $this->userId)->exists()) {
            DB::table('users')->insert([
                'id' => $this->userId, 'name' => 'Phase1D Replay Actor',
                'email' => 'phase1d-replay-' . $this->userId . '@example.invalid',
                'password' => bcrypt('not-a-real-password'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! DB::table('workspaces')->where('id', $this->ws)->exists()) {
            DB::table('workspaces')->insert([
                'id' => $this->ws, 'name' => 'Phase1D Replay WS', 'slug' => 'phase1d-replay-' . $this->ws,
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
    }

    /**
     * An event already marked fanned out with NO delivery row and a projected count
     * of 0 — exactly the state Phase 1C left event #1 in.
     */
    private function orphanedEvent(int $orderId = 980001, ?string $recordedAt = null): string
    {
        $eventId = (string) Str::uuid();

        DB::table('platform_events')->insert([
            'event_id' => $eventId, 'workspace_id' => $this->ws,
            'event_type' => 'domain.order.created', 'schema_version' => 1,
            'capability_key' => 'domain.register', 'actor_type' => 'user', 'actor_id' => $this->userId,
            'subject_type' => 'domain_order', 'subject_id' => (string) $orderId,
            'correlation_id' => (string) Str::uuid(),
            'payload_json' => json_encode([
                'order_id' => $orderId, 'currency' => 'USD', 'subtotal_minor' => 1817,
                'item_count' => 1, 'domains' => ['projection-test.com'],
            ]),
            'sensitivity' => 'internal', 'status' => Outbox::STATUS_PENDING,
            'occurred_at' => $recordedAt ?? self::RECORDED_AT,
            'recorded_at' => $recordedAt ?? self::RECORDED_AT,
            'attempt_count' => 0,
            'fanned_out_at' => now(),   // already stamped — fan-out will not revisit it
            'delivery_count' => 0,
        ]);

        return $eventId;
    }

    private function replay(): EventReplay
    {
        return new EventReplay();
    }

    private function token(EventReplay $r): string
    {
        return $r->confirmationTokenFor('platform.audit', ['domain.order.created'], self::FROM, self::TO, $this->ws);
    }

    private function execute(EventReplay $r): array
    {
        return $r->execute('platform.audit', ['domain.order.created'], self::FROM, self::TO,
            $this->userId, $this->token($r), $this->ws);
    }

    private function projected(string $eventId): int
    {
        return (int) DB::table('platform_events')->where('event_id', $eventId)->value('delivery_count');
    }

    private function ledger(string $eventId): int
    {
        return (int) DB::table('platform_event_deliveries')->where('event_id', $eventId)->count();
    }

    // ══════════════ 11. replay creates one delivery AND syncs the projection ══

    public function test_replay_creates_one_delivery_and_synchronises_the_projection(): void
    {
        $eventId = $this->orphanedEvent();

        $this->assertSame(0, $this->ledger($eventId));
        $this->assertSame(0, $this->projected($eventId));

        $result = $this->execute($this->replay());

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $this->ledger($eventId), 'one delivery obligation');
        $this->assertSame(1, $this->projected($eventId),
            'PHASE 1D: the projection must no longer be left behind at 0');
    }

    public function test_the_projection_matches_the_ledger_through_delivery(): void
    {
        $eventId = $this->orphanedEvent();
        $this->execute($this->replay());

        (new DeliveryWorker())->run();

        $this->assertSame(DeliveryWorker::STATUS_DELIVERED,
            DB::table('platform_event_deliveries')->where('event_id', $eventId)->value('status'));
        $this->assertSame(1, $this->projected($eventId), 'delivering does not change the count');
        $this->assertSame($this->ledger($eventId), $this->projected($eventId));
    }

    // ══════════════ 12. duplicate replay does not overcount ══

    public function test_duplicate_replay_does_not_overcount(): void
    {
        $eventId = $this->orphanedEvent();

        $first = $this->execute($this->replay());
        $this->assertSame(1, $first['created']);

        $second = $this->execute($this->replay());
        $third = $this->execute($this->replay());

        $this->assertSame(0, $second['created'], 'the duplicate is suppressed');
        $this->assertSame(0, $third['created']);

        $this->assertSame(1, $this->ledger($eventId), 'still one delivery identity');
        $this->assertSame(1, $this->projected($eventId),
            'three replays, count still 1 — sync() counts rows, it does not increment');
    }

    public function test_repeated_dry_runs_change_nothing(): void
    {
        $eventId = $this->orphanedEvent();
        $r = $this->replay();

        for ($i = 0; $i < 3; $i++) {
            $plan = $r->plan('platform.audit', ['domain.order.created'], self::FROM, self::TO, $this->ws);
            $this->assertTrue($plan['dry_run']);
            $this->assertSame(1, $plan['would_create']);
        }

        $this->assertSame(0, $this->ledger($eventId), 'planning writes nothing');
        $this->assertSame(0, $this->projected($eventId));
    }

    public function test_a_dry_run_after_execution_reports_nothing_left_to_do(): void
    {
        $this->orphanedEvent();
        $r = $this->replay();

        $this->execute($r);

        $plan = $r->plan('platform.audit', ['domain.order.created'], self::FROM, self::TO, $this->ws);

        $this->assertSame(1, $plan['already_delivered']);
        $this->assertSame(0, $plan['would_create']);
    }

    // ══════════════ 13. failed replay rolls back ledger AND projection ══

    /**
     * The delivery insert and the projection update share one transaction, so they
     * commit or roll back together. Proven by wrapping the call in an outer
     * transaction and rolling it back: if the two writes were in separate scopes, one
     * could survive.
     */
    public function test_a_rolled_back_replay_leaves_neither_ledger_nor_projection_changed(): void
    {
        $eventId = $this->orphanedEvent();

        $this->assertSame(0, $this->ledger($eventId));
        $this->assertSame(0, $this->projected($eventId));

        DB::beginTransaction();
        $this->execute($this->replay());

        // Visible inside the transaction...
        $this->assertSame(1, $this->ledger($eventId));
        $this->assertSame(1, $this->projected($eventId));

        DB::rollBack();

        // ...and gone together afterwards.
        $this->assertSame(0, $this->ledger($eventId), 'the delivery row rolled back');
        $this->assertSame(0, $this->projected($eventId), 'and so did the projection');
    }

    public function test_a_failed_replay_creates_no_audit_row(): void
    {
        $this->orphanedEvent();
        $auditBefore = DB::table('audit_logs')->where('workspace_id', $this->ws)->count();

        DB::beginTransaction();
        $this->execute($this->replay());
        DB::rollBack();

        $this->assertSame($auditBefore,
            DB::table('audit_logs')->where('workspace_id', $this->ws)->count(),
            'the replay governance record rolls back with it');
    }

    public function test_replay_refuses_a_token_that_does_not_match_the_plan(): void
    {
        $eventId = $this->orphanedEvent();

        try {
            $this->replay()->execute('platform.audit', ['domain.order.created'], self::FROM, self::TO,
                $this->userId, 'not-the-right-token', $this->ws);
            $this->fail('a wrong token must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('confirmation token', $e->getMessage());
        }

        $this->assertSame(0, $this->ledger($eventId), 'nothing was written');
        $this->assertSame(0, $this->projected($eventId));
    }

    // ══════════════ 14. repair touches only incorrect events ══

    public function test_projection_repair_changes_only_incorrect_events(): void
    {
        // Three events: two correct, one deliberately wrong.
        $correctA = $this->orphanedEvent(980010);
        $correctB = $this->orphanedEvent(980011);
        $wrong = $this->orphanedEvent(980012);

        foreach ([$correctA, $correctB, $wrong] as $id) {
            DB::table('platform_event_deliveries')->insert([
                'event_id' => $id, 'subscriber_key' => 'platform.audit', 'subscriber_version' => 1,
                'workspace_id' => $this->ws, 'status' => DeliveryWorker::STATUS_DELIVERED,
                'attempt_count' => 1, 'delivered_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Make the two correct ones correct, and leave $wrong at 0.
        DeliveryProjection::sync($correctA);
        DeliveryProjection::sync($correctB);

        $stampA = DB::table('platform_events')->where('event_id', $correctA)->value('delivery_count');
        $stampB = DB::table('platform_events')->where('event_id', $correctB)->value('delivery_count');

        $this->assertSame(1, (int) $stampA);
        $this->assertSame(1, (int) $stampB);
        $this->assertSame(0, $this->projected($wrong));

        $repaired = DeliveryProjection::repairDrifted($this->ws);

        $this->assertCount(1, $repaired, 'only the drifted event is touched');
        $this->assertSame($wrong, $repaired[0]['event_id']);
        $this->assertSame(0, $repaired[0]['from']);
        $this->assertSame(1, $repaired[0]['to']);

        $this->assertSame(1, $this->projected($correctA));
        $this->assertSame(1, $this->projected($correctB));
        $this->assertSame(1, $this->projected($wrong));
    }

    public function test_repair_is_idempotent(): void
    {
        $eventId = $this->orphanedEvent();
        $this->execute($this->replay());

        $this->assertSame([], DeliveryProjection::repairDrifted($this->ws),
            'nothing drifts after a correct replay');
        $this->assertSame(1, $this->projected($eventId));
    }

    public function test_the_audit_is_read_only(): void
    {
        $eventId = $this->orphanedEvent();

        DB::table('platform_event_deliveries')->insert([
            'event_id' => $eventId, 'subscriber_key' => 'platform.audit', 'subscriber_version' => 1,
            'workspace_id' => $this->ws, 'status' => DeliveryWorker::STATUS_PENDING,
            'attempt_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $audit = DeliveryProjection::audit($this->ws);

        $this->assertCount(1, $audit['drifted']);
        $this->assertSame(0, $this->projected($eventId), 'audit() must not repair anything');
    }

    // ══════════════ the fan-out drift path ══

    public function test_re_running_fanout_does_not_reset_a_correct_count_to_zero(): void
    {
        // A normal event, fanned out properly.
        $eventId = (string) Str::uuid();
        DB::table('platform_events')->insert([
            'event_id' => $eventId, 'workspace_id' => $this->ws,
            'event_type' => 'domain.order.created', 'schema_version' => 1,
            'capability_key' => 'domain.register', 'actor_type' => 'user', 'actor_id' => $this->userId,
            'subject_type' => 'domain_order', 'subject_id' => '980020',
            'correlation_id' => (string) Str::uuid(),
            'payload_json' => json_encode([
                'order_id' => 980020, 'currency' => 'USD', 'subtotal_minor' => 1817,
                'item_count' => 1, 'domains' => ['refanout.com'],
            ]),
            'sensitivity' => 'internal', 'status' => Outbox::STATUS_PENDING,
            'occurred_at' => self::RECORDED_AT, 'recorded_at' => self::RECORDED_AT,
            'attempt_count' => 0, 'delivery_count' => 0,
        ]);

        (new EventFanOut())->run();
        $this->assertSame(1, $this->ledger($eventId));
        $this->assertSame(1, $this->projected($eventId));

        // Force it back into the fan-out queue. The insert is suppressed by the
        // unique index, so `created + skipped` would have written 0 here.
        DB::table('platform_events')->where('event_id', $eventId)->update(['fanned_out_at' => null]);
        (new EventFanOut())->run();

        $this->assertSame(1, $this->ledger($eventId));
        $this->assertSame(1, $this->projected($eventId),
            'a repeated fan-out must not rewrite a correct count to 0');
    }

    /**
     * DeliveryProjection is allow-listed to touch platform_events. Prove the scope of
     * that permission: ONE column on an existing row, never an insert or a delete, and
     * never a write to the delivery ledger it derives from.
     */
    public function test_the_projection_writer_only_touches_delivery_count(): void
    {
        $src = file_get_contents(app_path('Core/PlatformEvents/DeliveryProjection.php'));

        foreach (['->insert(', '->delete(', '->truncate(', '->upsert(', 'DB::statement('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src,
                "DeliveryProjection must never {$forbidden} — it maintains a counter, nothing more");
        }

        // Exactly one update, and it sets exactly one column.
        $this->assertSame(1, substr_count($src, '->update('),
            'there must be exactly one write in the projection writer');
        $this->assertStringContainsString("->update(['delivery_count' => \$count])", $src,
            'the single write must set only delivery_count');

        // It must never write to the ledger it reads.
        $ledgerWrites = preg_match(
            "/platform_event_deliveries'\)[^;]*->(insert|update|delete)\(/s", $src
        );
        $this->assertSame(0, $ledgerWrites, 'the ledger is read-only to the projection');
    }

    public function test_the_projection_is_derived_not_incremented(): void
    {
        $src = file_get_contents(app_path('Core/PlatformEvents/DeliveryProjection.php'));

        $this->assertStringContainsString('->count()', $src, 'sync() must count ledger rows');
        $this->assertStringNotContainsString('increment(', $src,
            'the projection must never be advanced arithmetically');
        $this->assertStringNotContainsString('delivery_count + 1', $src);
    }

    public function test_the_ledger_remains_authoritative(): void
    {
        $eventId = $this->orphanedEvent();
        $this->execute($this->replay());

        // Corrupt the projection by hand; the ledger is unchanged.
        DB::table('platform_events')->where('event_id', $eventId)->update(['delivery_count' => 99]);

        $audit = DeliveryProjection::audit($this->ws);
        $this->assertCount(1, $audit['drifted']);
        $this->assertSame(99, $audit['drifted'][0]['projected']);
        $this->assertSame(1, $audit['drifted'][0]['ledger']);

        // Repair follows the LEDGER, not the projection.
        DeliveryProjection::repairDrifted($this->ws);
        $this->assertSame(1, $this->projected($eventId));
    }
}
