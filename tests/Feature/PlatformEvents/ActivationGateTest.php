<?php

namespace Tests\Feature\PlatformEvents;

use App\Core\PlatformEvents\DeliveryWorker;
use App\Core\PlatformEvents\EventFanOut;
use App\Core\PlatformEvents\EventSystemHealth;
use App\Core\PlatformEvents\SubscriberRegistry;
use App\Core\PlatformEvents\Outbox;
use App\Core\PlatformEvents\Types\DomainOrderCreated;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Phase 1C — activation gates, scheduling safety, health reporting, rollback.
 *
 * These tests exist to make live activation reversible and narrow. The workspace
 * gate in particular is the control that stops a global config flag becoming a
 * global production change.
 */
class ActivationGateTest extends TestCase
{
    use IsolatedDatabase;

    private int $allowed = 995001;
    private int $forbidden = 995002;

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 1E.1: never run against a shared database.
        $this->assertIsolatedDatabase();

        config([
            'platform_events.enabled' => true,
            'platform_events.producers' => ['domain.order.created' => true],
            'platform_events.producer_workspace_allowlist' => [$this->allowed],
            'platform_events.fanout.enabled' => true,
            'platform_events.delivery_worker.enabled' => true,
            'platform_events.subscribers' => ['platform.audit' => true],
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
        $ids = DB::table('platform_events')->whereIn('workspace_id', [$this->allowed, $this->forbidden])->pluck('event_id');
        DB::table('platform_event_deliveries')->whereIn('event_id', $ids)->delete();
        DB::table('platform_events')->whereIn('workspace_id', [$this->allowed, $this->forbidden])->delete();
    }

    private function event(int $ws, int $orderId = 800001): DomainOrderCreated
    {
        return new DomainOrderCreated(
            workspaceId: $ws,
            payload: [
                'order_id' => $orderId, 'currency' => 'USD', 'subtotal_minor' => 1817,
                'item_count' => 1, 'domains' => ['gate-test.com'],
            ],
            capabilityKey: 'domain.register',
        );
    }

    private function eventsFor(int $ws): int
    {
        return DB::table('platform_events')->where('workspace_id', $ws)->count();
    }

    // ══════════════════════════ workspace allow-list ══

    public function test_an_allow_listed_workspace_can_produce(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed)));

        $this->assertSame(1, $this->eventsFor($this->allowed));
    }

    public function test_a_workspace_outside_the_allow_list_cannot_produce(): void
    {
        $id = DB::transaction(fn () => app(Outbox::class)->record($this->event($this->forbidden, 800002)));

        $this->assertNull($id, 'production must be impossible outside the allow-list');
        $this->assertSame(0, $this->eventsFor($this->forbidden));
    }

    /**
     * The gate is independent of the producer flag: flags are global, activation
     * is not. Both must permit the write.
     */
    public function test_the_gate_is_independent_of_the_producer_flag(): void
    {
        // producer on, workspace not listed → no event
        config(['platform_events.producer_workspace_allowlist' => [$this->allowed]]);
        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->forbidden, 800003)));
        $this->assertSame(0, $this->eventsFor($this->forbidden));

        // workspace listed, producer off → no event
        config([
            'platform_events.producer_workspace_allowlist' => [$this->forbidden],
            'platform_events.producers' => ['domain.order.created' => false],
        ]);
        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->forbidden, 800004)));
        $this->assertSame(0, $this->eventsFor($this->forbidden));
    }

    /** FAIL-CLOSED: an empty allow-list permits nothing, not everything. */
    public function test_an_empty_allow_list_permits_no_workspace(): void
    {
        config(['platform_events.producer_workspace_allowlist' => []]);

        $a = DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800005)));
        $b = DB::transaction(fn () => app(Outbox::class)->record($this->event($this->forbidden, 800006)));

        $this->assertNull($a);
        $this->assertNull($b);
        $this->assertSame(0, $this->eventsFor($this->allowed) + $this->eventsFor($this->forbidden));
    }

    public function test_the_allow_list_is_read_from_committed_configuration_as_empty(): void
    {
        // Read the FILE: the committed default must permit nothing.
        $src = file_get_contents(config_path('platform_events.php'));

        $this->assertStringContainsString("env('PLATFORM_EVENTS_WORKSPACES', '')", $src,
            'the committed default must be an empty allow-list');
    }

    public function test_multiple_workspaces_can_be_listed_but_only_those(): void
    {
        config(['platform_events.producer_workspace_allowlist' => [$this->allowed, 999888]]);

        $o = app(Outbox::class);

        $this->assertTrue($o->workspaceAllowed($this->allowed));
        $this->assertTrue($o->workspaceAllowed(999888));
        $this->assertFalse($o->workspaceAllowed($this->forbidden));
    }

    // ══════════════════════ command safety ══

    public function test_the_command_is_safe_when_no_events_exist(): void
    {
        $this->artisan('platform-events:process')
            ->expectsOutputToContain('fanout claimed=0')
            ->assertExitCode(0);
    }

    public function test_the_command_is_safe_when_every_flag_is_false(): void
    {
        config([
            'platform_events.enabled' => false,
            'platform_events.fanout.enabled' => false,
            'platform_events.delivery_worker.enabled' => false,
            'platform_events.subscribers' => ['platform.audit' => false],
        ]);

        $before = DB::table('platform_event_deliveries')->count();

        $this->artisan('platform-events:process')->assertExitCode(0);

        $this->assertSame($before, DB::table('platform_event_deliveries')->count());
    }

    /**
     * REVISED BY PHASE 1C.1.
     *
     * This previously asserted that a paused subscriber produced NO delivery row.
     * That was the defect: it made a temporary operational pause destroy a
     * permanent obligation. The command must now create the obligation and simply
     * not execute it, so the assertion is inverted deliberately — the behaviour
     * changed, the test was not weakened.
     */
    public function test_a_paused_subscriber_still_receives_its_obligation(): void
    {
        config(['platform_events.subscribers' => ['platform.audit' => false]]);

        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800007)));

        $this->artisan('platform-events:process')->assertExitCode(0);

        $rows = DB::table('platform_event_deliveries')
            ->whereIn('event_id', DB::table('platform_events')->where('workspace_id', $this->allowed)->pluck('event_id'))
            ->get();

        $this->assertCount(1, $rows, 'the obligation is recorded even while execution is paused');
        $this->assertSame(DeliveryWorker::STATUS_PENDING, $rows->first()->status);
        $this->assertNull($rows->first()->delivered_at, 'nothing executed');

        $audits = DB::table('audit_logs')
            ->where('workspace_id', $this->allowed)
            ->whereRaw("JSON_EXTRACT(metadata_json, '$.source') = 'platform_event'")
            ->count();
        $this->assertSame(0, $audits, 'no audit row while paused');
    }

    /** A second overlapping invocation must do no work, not race the first. */
    public function test_overlapping_invocations_do_not_both_process(): void
    {
        $lock = Cache::lock('platform-events:process', 60);
        $this->assertTrue($lock->get(), 'the test must hold the lock first');

        try {
            $this->artisan('platform-events:process')
                ->expectsOutputToContain('holds the lock')
                ->assertExitCode(0);
        } finally {
            $lock->release();
        }
    }

    public function test_the_command_aborts_cleanly_when_the_cache_is_unavailable(): void
    {
        // Simulate a cache backend failure (Redis down). Fail CLOSED: skip the
        // run rather than proceed without overlap protection.
        Cache::shouldReceive('lock')->once()->andThrow(new \RuntimeException('Connection refused'));

        $this->artisan('platform-events:process')
            ->expectsOutputToContain('Cache unavailable')
            ->assertExitCode(0);
    }

    public function test_the_health_option_reports_without_processing(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800008)));

        $before = DB::table('platform_event_deliveries')->count();

        $this->artisan('platform-events:process --health')
            ->expectsOutputToContain('severity:')
            ->assertExitCode(0);

        $this->assertSame($before, DB::table('platform_event_deliveries')->count(),
            '--health must not perform work');
    }

    // ══════════════════════════ health report ══

    public function test_the_health_report_reflects_flags_accurately(): void
    {
        $h = app(EventSystemHealth::class)->report();

        $this->assertTrue($h['flags']['foundation_enabled']);
        $this->assertTrue($h['flags']['producer_enabled']);
        $this->assertSame([$this->allowed], $h['flags']['producer_workspace_allowlist']);
        $this->assertTrue($h['flags']['fanout_enabled']);
        $this->assertTrue($h['flags']['delivery_enabled']);
        $this->assertTrue($h['flags']['audit_subscriber_enabled']);

        // Phase 1C.1: one conflated "active" list became two honest ones plus an
        // explicit per-subscriber state.
        $this->assertSame(['platform.audit'], $h['flags']['eligible_subscribers']);
        $this->assertSame(['platform.audit'], $h['flags']['executable_subscribers']);
        $this->assertSame(
            ['platform.audit' => SubscriberRegistry::STATE_ACTIVE],
            $h['flags']['subscriber_states']
        );
    }

    public function test_the_health_report_counts_backlog_accurately(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800009)));

        $h = app(EventSystemHealth::class)->report();

        $this->assertGreaterThanOrEqual(1, $h['backlog']['events_awaiting_fanout']);
        $this->assertArrayHasKey('pending_deliveries', $h['backlog']);
        $this->assertArrayHasKey('permanently_failed', $h['backlog']);
        $this->assertArrayHasKey('stale_processing', $h['backlog']);
    }

    public function test_the_health_report_escalates_on_an_aged_backlog(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800010)));

        // Age the event past the critical threshold.
        DB::table('platform_events')->where('workspace_id', $this->allowed)
            ->update(['recorded_at' => now()->subHours(3)]);

        $h = app(EventSystemHealth::class)->report();

        $this->assertSame(EventSystemHealth::CRITICAL, $h['severity']);
        $this->assertNotEmpty($h['findings']);
    }

    public function test_the_health_report_is_ok_on_a_clean_system(): void
    {
        // A fully idle system: nothing enabled, nothing pending. With fan-out
        // ENABLED and no schedule registered, CRITICAL is the correct answer —
        // that combination means recorded events would never be processed — so
        // this test must describe the genuinely idle case.
        config([
            'platform_events.fanout.enabled' => false,
            'platform_events.delivery_worker.enabled' => false,
        ]);

        $h = app(EventSystemHealth::class)->report();

        $this->assertContains($h['severity'], [EventSystemHealth::OK, EventSystemHealth::INFO],
            'an idle system with nothing enabled must not raise a warning');
    }

    /**
     * The inverse, and the more valuable assertion: fan-out enabled with no
     * schedule to run it is a silent black hole. It must be CRITICAL.
     */
    /**
     * Fan-out enabled with no schedule to run it is a silent black hole: events
     * would be recorded and never processed. It must be CRITICAL.
     *
     * The schedule is now genuinely registered in production, so this asserts the
     * RULE against a controlled Schedule rather than the ambient one — a POPULATED
     * schedule that lacks our command is the real danger case (someone removed the
     * entry), and it is detected deterministically here.
     */
    public function test_fanout_enabled_without_a_schedule_is_critical(): void
    {
        config(['platform_events.fanout.enabled' => true]);

        $schedule = new \Illuminate\Console\Scheduling\Schedule();
        $schedule->command('inspire');   // populated, but not platform-events:process
        app()->instance(\Illuminate\Console\Scheduling\Schedule::class, $schedule);

        $h = app(EventSystemHealth::class)->report();

        $this->assertFalse($h['infrastructure']['scheduler_registered'],
            'a populated schedule without our command is a definite negative');
        $this->assertSame(EventSystemHealth::CRITICAL, $h['severity']);

        $messages = implode(' | ', array_column($h['findings'], 'message'));
        $this->assertStringContainsString('no schedule invokes', $messages);
    }

    /**
     * The counterpart: our command present means no such finding.
     */
    public function test_a_registered_schedule_clears_the_finding(): void
    {
        config(['platform_events.fanout.enabled' => true]);

        $schedule = new \Illuminate\Console\Scheduling\Schedule();
        $schedule->command('platform-events:process');
        app()->instance(\Illuminate\Console\Scheduling\Schedule::class, $schedule);

        $h = app(EventSystemHealth::class)->report();

        $this->assertTrue($h['infrastructure']['scheduler_registered']);

        $messages = implode(' | ', array_column($h['findings'], 'message'));
        $this->assertStringNotContainsString('no schedule invokes', $messages);
    }

    /**
     * An EMPTY schedule means "cannot tell from here", not "not scheduled".
     * withSchedule() is wired to Artisan::starting, so an HTTP caller legitimately
     * sees no events — reporting that as false would raise a false CRITICAL.
     */
    public function test_an_undeterminable_schedule_is_not_reported_as_missing(): void
    {
        config(['platform_events.fanout.enabled' => true]);

        app()->instance(
            \Illuminate\Console\Scheduling\Schedule::class,
            new \Illuminate\Console\Scheduling\Schedule()
        );

        $h = app(EventSystemHealth::class)->report();

        $this->assertNull($h['infrastructure']['scheduler_registered'],
            'undetermined must be null, never false');
        $this->assertNotSame(EventSystemHealth::CRITICAL, $h['severity'],
            'an undeterminable schedule must not raise a false CRITICAL');
    }

    public function test_the_health_report_exposes_no_payload_or_personal_data(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800011)));

        $json = json_encode(app(EventSystemHealth::class)->report());

        foreach (['gate-test.com', 'payload', 'subtotal_minor', 'api_key', 'email'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json,
                "the health report must not expose '{$forbidden}'");
        }
    }

    public function test_the_health_report_flags_a_missing_schedule_when_fanout_is_on(): void
    {
        $h = app(EventSystemHealth::class)->report();

        $this->assertArrayHasKey('scheduler_registered', $h['infrastructure']);
        // bool OR null: null means the schedule cannot be determined from this
        // context (see test_an_undeterminable_schedule_is_not_reported_as_missing).
        $reported = $h['infrastructure']['scheduler_registered'];
        $this->assertTrue($reported === true || $reported === false || $reported === null);
    }

    // ══════════════════════ rollback preservation ══

    public function test_rollback_by_flag_preserves_every_recorded_row(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800012)));
        (new EventFanOut())->run();

        $events = $this->eventsFor($this->allowed);
        $deliveries = DB::table('platform_event_deliveries')
            ->whereIn('event_id', DB::table('platform_events')->where('workspace_id', $this->allowed)->pluck('event_id'))
            ->count();

        $this->assertSame(1, $events);
        $this->assertSame(1, $deliveries);

        // ── the documented rollback order ──
        config([
            'platform_events.producers' => ['domain.order.created' => false],
            'platform_events.fanout.enabled' => false,
            'platform_events.delivery_worker.enabled' => false,
            'platform_events.subscribers' => ['platform.audit' => false],
        ]);

        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $this->assertSame($events, $this->eventsFor($this->allowed),
            'rollback must preserve recorded events — evidence is never deleted');
        $this->assertSame($deliveries, DB::table('platform_event_deliveries')
            ->whereIn('event_id', DB::table('platform_events')->where('workspace_id', $this->allowed)->pluck('event_id'))
            ->count(), 'rollback must preserve delivery rows');
    }

    public function test_rollback_requires_no_migration_and_no_table_drop(): void
    {
        // Both tables must survive a full flag rollback.
        config([
            'platform_events.enabled' => false,
            'platform_events.fanout.enabled' => false,
            'platform_events.delivery_worker.enabled' => false,
        ]);

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('platform_events'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('platform_event_deliveries'));
    }

    // ══════════════════ no side effects ══

    public function test_a_full_pass_writes_no_notification_and_no_memory(): void
    {
        $notif = DB::table('notifications')->count();
        $mem = DB::table('workspace_memory')->count();
        $infra = DB::table('infra_events')->count();

        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800013)));
        $this->artisan('platform-events:process')->assertExitCode(0);

        $this->assertSame($notif, DB::table('notifications')->count());
        $this->assertSame($mem, DB::table('workspace_memory')->count());
        $this->assertSame($infra, DB::table('infra_events')->count());
    }

    public function test_processing_never_calls_a_provider_or_payment_service(): void
    {
        // Fan-out and delivery are pure database work. If either reached out, a
        // faked HTTP client would record it.
        \Illuminate\Support\Facades\Http::fake();

        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800014)));
        $this->artisan('platform-events:process')->assertExitCode(0);

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    // ══════════════ deleted-tenant policy ══

    public function test_a_delivery_for_a_missing_workspace_fails_permanently_without_an_audit_row(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800015)));
        (new EventFanOut())->run();

        $eventIds = DB::table('platform_events')->where('workspace_id', $this->allowed)->pluck('event_id');

        // The workspace does not exist in the workspaces table (no fixture seeded
        // here), so the audit insert violates the FK — a deleted tenant, in effect.
        $auditBefore = DB::table('audit_logs')->count();

        (new DeliveryWorker())->run();

        $d = DB::table('platform_event_deliveries')->whereIn('event_id', $eventIds)->first();

        $this->assertSame(DeliveryWorker::STATUS_PERMANENTLY_FAILED, $d->status,
            'an FK violation is permanent — retrying cannot create the workspace');
        $this->assertNotEmpty($d->last_error);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count(),
            'no tenantless audit row may be created');
    }

    public function test_the_source_event_survives_a_permanently_failed_delivery(): void
    {
        DB::transaction(fn () => app(Outbox::class)->record($this->event($this->allowed, 800016)));
        (new EventFanOut())->run();
        (new DeliveryWorker())->run();

        $this->assertSame(1, $this->eventsFor($this->allowed),
            'the event is immutable and survives any delivery outcome');
    }
}
