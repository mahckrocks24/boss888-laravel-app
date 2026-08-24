<?php

namespace Tests\Feature\PlatformEvents;

use App\Core\PlatformEvents\DeliveryWorker;
use App\Core\PlatformEvents\EventFanOut;
use App\Core\PlatformEvents\EventReplay;
use App\Core\PlatformEvents\SubscriberDeclaration;
use App\Core\PlatformEvents\SubscriberRegistry;
use App\Core\PlatformEvents\Subscribers\AuditSubscriber;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Architecture and governance enforcement for Phase 1B.
 *
 * Prevents the failure mode this whole programme exists to stop: a subsystem
 * quietly wiring its own consumer, its own delivery state, or reaching back over
 * history without approval.
 */
class SubscriberGovernanceTest extends TestCase
{
    use IsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 1E.1: never run against a shared database.
        $this->assertIsolatedDatabase();
    }

    private function sourceFiles(): array
    {
        $out = [];

        foreach (['app', 'config', 'routes'] as $root) {
            $dir = base_path($root);

            if (! is_dir($dir)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
                if (! $f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }

                $rel = str_replace(base_path() . '/', '', $f->getPathname());

                if (str_contains($rel, '.bak')) {
                    continue;
                }

                $out[$rel] = file_get_contents($f->getPathname());
            }
        }

        return $out;
    }

    // ══════════════════════ declaration completeness ══

    public function test_every_subscriber_declares_a_complete_contract(): void
    {
        $all = SubscriberRegistry::all();

        $this->assertNotSame([], $all, 'expected at least one declared subscriber');

        foreach ($all as $key => $decl) {
            $this->assertInstanceOf(SubscriberDeclaration::class, $decl);
            $this->assertSame($key, $decl->key, 'registry key and declaration key must agree');
            $this->assertGreaterThanOrEqual(1, $decl->version, "{$key} must declare a version");
            $this->assertNotSame([], $decl->accepts, "{$key} must declare accepted event types");
            $this->assertNotSame('', $decl->activatedAt, "{$key} must declare an activation timestamp");
            $this->assertGreaterThanOrEqual(1, $decl->maxAttempts);
            $this->assertNotSame([], $decl->backoff, "{$key} must declare a retry ladder");
            $this->assertNotSame([], $decl->sensitivityAllowance, "{$key} must declare a sensitivity allowance");
            $this->assertSame('workspace_scoped', $decl->tenancy, "{$key} must be tenant-scoped");
        }
    }

    public function test_a_declaration_without_an_activation_timestamp_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/activatedAt/');

        new SubscriberDeclaration(
            key: 'test.thing', version: 1, accepts: ['a.b.c' => [1]],
            activatedAt: 'whenever', maxAttempts: 3, backoff: [10],
            sensitivityAllowance: ['internal'],
        );
    }

    public function test_a_declaration_without_a_version_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriberDeclaration(
            key: 'test.thing', version: 0, accepts: ['a.b.c' => [1]],
            activatedAt: '2026-07-29 00:00:00', maxAttempts: 3, backoff: [10],
            sensitivityAllowance: ['internal'],
        );
    }

    public function test_unknown_subscriber_key_is_denied(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DENIED/');

        SubscriberRegistry::declaration('platform.not_a_thing');
    }

    // ══════════════════ centralisation / no local wiring ══

    public function test_subscribers_are_declared_only_in_the_central_registry(): void
    {
        $allowed = [
            'app/Core/PlatformEvents/SubscriberRegistry.php',
            'app/Core/PlatformEvents/SubscriberDeclaration.php',
            'config/platform_events.php',
        ];

        $offenders = [];

        foreach ($this->sourceFiles() as $rel => $src) {
            // Look for CONSTRUCTION, not a type hint. EventFanOut and
            // EventReplay legitimately ACCEPT a SubscriberDeclaration;
            // only the registry may create one.
            if (! str_contains($src, 'new SubscriberDeclaration(')) {
                continue;
            }

            if (! in_array($rel, $allowed, true)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders,
            "Subscriber declarations must live only in SubscriberRegistry:\n  - " . implode("\n  - ", $offenders));
    }

    public function test_domain_commerce_cannot_invoke_a_subscriber_directly(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $rel => $src) {
            // The producer side must never name a subscriber or a delivery.
            if (! str_starts_with($rel, 'app/Services/Domains/')
                && ! str_starts_with($rel, 'app/Jobs/')
                && ! str_starts_with($rel, 'app/Http/Controllers/')) {
                continue;
            }

            foreach (['AuditSubscriber', 'SubscriberRegistry', 'DeliveryWorker', 'platform_event_deliveries'] as $forbidden) {
                if (str_contains($src, $forbidden)) {
                    $offenders[] = "{$rel} references {$forbidden}";
                }
            }
        }

        $this->assertSame([], $offenders,
            "A producer's responsibility ends at recording the event:\n  - " . implode("\n  - ", $offenders));
    }

    public function test_producers_cannot_create_delivery_rows(): void
    {
        $allowed = [
            'app/Core/PlatformEvents/EventFanOut.php',
            'app/Core/PlatformEvents/DeliveryWorker.php',
            'app/Core/PlatformEvents/EventReplay.php',
            // Phase 1C — READ-ONLY. Counts deliveries for the health report.
            'app/Core/PlatformEvents/EventSystemHealth.php',
            // Phase 1D — both READ the ledger only. DeliveryProjection derives
            // platform_events.delivery_count from it and never writes to it;
            // ChainVerifier only compares the two.
            'app/Core/PlatformEvents/ChainVerifier.php',
            'app/Core/PlatformEvents/DeliveryProjection.php',
            'database/migrations/2026_07_29_200000_create_platform_event_deliveries_table.php',
        ];

        $offenders = [];

        foreach ($this->sourceFiles() as $rel => $src) {
            if (! preg_match("/['\"]platform_event_deliveries['\"]/", $src)) {
                continue;
            }

            if ($rel === 'config/platform_events.php') {
                continue;
            }

            if (! in_array($rel, $allowed, true)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders,
            "Only fan-out, the delivery worker and replay may touch the delivery ledger:\n  - "
            . implode("\n  - ", $offenders));
    }

    /**
     * The health reporter is allow-listed as a reader. Prove it: any insert,
     * update or delete against either platform-event table would make that
     * allow-list entry a lie.
     */
    public function test_the_health_reporter_is_read_only(): void
    {
        $src = file_get_contents(app_path('Core/PlatformEvents/EventSystemHealth.php'));

        foreach (['->insert(', '->update(', '->delete(', '->truncate(', 'DB::statement('] as $mutation) {
            $this->assertStringNotContainsString($mutation, $src,
                "EventSystemHealth is allow-listed as read-only but contains '{$mutation}'");
        }
    }

    // ══════════════════ audit subscriber constraints ══

    public function test_the_audit_subscriber_accepts_only_declared_event_and_schema_pairs(): void
    {
        $decl = SubscriberRegistry::declaration(AuditSubscriber::KEY);

        $this->assertTrue($decl->acceptsType('domain.order.created'));
        $this->assertTrue($decl->acceptsSchema('domain.order.created', 1));

        $this->assertFalse($decl->acceptsSchema('domain.order.created', 2), 'v2 is not declared and must not be accepted');
        $this->assertFalse($decl->acceptsType('domain.registered'), 'an undeclared type must not be accepted');
        $this->assertFalse($decl->acceptsType('domain.payment.confirmed'));
    }

    public function test_the_audit_subscriber_refuses_to_run_outside_the_delivery_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/delivery transaction/');

        (new AuditSubscriber())->handle((object) [
            'event_type' => 'domain.order.created', 'workspace_id' => 1, 'actor_type' => 'system',
            'actor_id' => null, 'subject_type' => 'domain_order', 'subject_id' => '1',
            'event_id' => 'x', 'correlation_id' => 'y', 'causation_id' => null,
            'capability_key' => null, 'schema_version' => 1, 'occurred_at' => '2026-07-29 00:00:00',
        ], []);
    }

    public function test_the_audit_subscriber_emits_no_customer_facing_side_effects(): void
    {
        $decl = SubscriberRegistry::declaration(AuditSubscriber::KEY);

        $this->assertFalse($decl->emitsCustomerFacingSideEffects,
            'audit must never contact a customer');
    }

    public function test_only_the_audit_subscriber_is_declared_in_phase_1b(): void
    {
        $this->assertSame(['platform.audit'], array_keys(SubscriberRegistry::all()),
            'Phase 1B authorises exactly one subscriber — no notifications, memory or analytics');
    }

    public function test_the_audit_subscriber_source_contains_no_notification_or_memory_writes(): void
    {
        $src = file_get_contents(app_path('Core/PlatformEvents/Subscribers/AuditSubscriber.php'));

        foreach (['NotificationService', 'workspace_memory', 'WorkspaceMemoryService', 'notifications'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src,
                "the audit subscriber must not reference {$forbidden}");
        }
    }

    // ══════════════════════════ feature flags ══

    public function test_all_four_flags_default_to_false_in_committed_configuration(): void
    {
        // Read the FILE: runtime config is mutated by sibling tests.
        $src = file_get_contents(config_path('platform_events.php'));

        foreach ([
            "env('PLATFORM_EVENTS_ENABLED', false)",
            "env('PLATFORM_EVENTS_DOMAIN_ORDER_CREATED', false)",
            "env('PLATFORM_EVENTS_FANOUT_ENABLED', false)",
            "env('PLATFORM_EVENTS_DELIVERY_ENABLED', false)",
            "env('PLATFORM_EVENTS_SUBSCRIBER_AUDIT', false)",
            // Phase 1C: the workspace gate must also ship permitting nothing.
            "env('PLATFORM_EVENTS_WORKSPACES', '')",
        ] as $expected) {
            $this->assertStringContainsString($expected, $src, "committed config must default: {$expected}");
        }
    }

    /**
     * SUPERSEDED BY PHASE 1C.1.
     *
     * This test used to assert that a subscriber was "inactive" unless BOTH
     * fanout.enabled and its own flag were on — and fan-out used that same notion
     * to decide whether to create a delivery row. That coupling is the defect that
     * lost Phase 1C event #1, so the assertion could not be kept: it encoded the
     * bug. It is replaced by the correct separation, asserted explicitly.
     *
     * Full behavioural coverage lives in SubscriberEligibilityTest.
     */
    public function test_eligibility_and_execution_are_separate_concerns(): void
    {
        // Execution paused: still ELIGIBLE (obligations accrue), not EXECUTABLE.
        config(['platform_events.fanout.enabled' => true, 'platform_events.subscribers' => ['platform.audit' => false]]);
        $this->assertSame(['platform.audit'], array_keys(SubscriberRegistry::eligible()),
            'a paused subscriber still holds obligations');
        $this->assertSame([], array_keys(SubscriberRegistry::executable()),
            'a paused subscriber runs nothing');
        $this->assertSame(SubscriberRegistry::STATE_EXECUTION_PAUSED,
            SubscriberRegistry::state('platform.audit'));

        // Execution enabled: eligible AND executable.
        config(['platform_events.subscribers' => ['platform.audit' => true]]);
        $this->assertSame(['platform.audit'], array_keys(SubscriberRegistry::eligible()));
        $this->assertSame(['platform.audit'], array_keys(SubscriberRegistry::executable()));
        $this->assertSame(SubscriberRegistry::STATE_ACTIVE,
            SubscriberRegistry::state('platform.audit'));

        // fanout.enabled is fan-out INFRASTRUCTURE, not a subscriber property. It
        // must not affect eligibility — EventFanOut::run() checks it for itself.
        config(['platform_events.fanout.enabled' => false]);
        $this->assertSame(['platform.audit'], array_keys(SubscriberRegistry::eligible()),
            'fan-out being off does not dissolve an obligation');
        $this->assertSame(0, (new \App\Core\PlatformEvents\EventFanOut())->run()['claimed'],
            'but fan-out itself still does nothing while disabled');
    }

    public function test_the_four_subscriber_states_are_distinct(): void
    {
        $states = [
            SubscriberRegistry::STATE_ACTIVE,
            SubscriberRegistry::STATE_EXECUTION_PAUSED,
            SubscriberRegistry::STATE_DISABLED_FOR_FUTURE,
            SubscriberRegistry::STATE_RETIRED,
        ];

        $this->assertCount(4, array_unique($states),
            'four meanings must have four distinct values, not one boolean');
    }

    // ══════════════════════════════ replay ══

    public function test_replay_requires_an_explicit_window(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/explicit timestamp/');

        (new EventReplay())->plan('platform.audit', ['domain.order.created'], 'all', 'history');
    }

    public function test_replay_requires_an_explicit_event_type_filter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/event type filter/');

        (new EventReplay())->plan('platform.audit', [], '2026-01-01 00:00:00', '2026-12-31 00:00:00');
    }

    public function test_replay_rejects_an_event_type_the_subscriber_does_not_accept(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EventReplay())->plan('platform.audit', ['domain.registered'], '2026-01-01 00:00:00', '2026-12-31 00:00:00');
    }

    public function test_replay_plan_is_a_dry_run_that_writes_nothing(): void
    {
        $before = DB::table('platform_event_deliveries')->count();

        $plan = (new EventReplay())->plan(
            'platform.audit', ['domain.order.created'], '2026-01-01 00:00:00', '2026-12-31 00:00:00'
        );

        $this->assertTrue($plan['dry_run']);
        $this->assertArrayHasKey('would_create', $plan);
        $this->assertSame($before, DB::table('platform_event_deliveries')->count(), 'plan() must write nothing');
    }

    public function test_replay_execution_requires_the_matching_confirmation_token(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/confirmation token/');

        (new EventReplay())->execute(
            'platform.audit', ['domain.order.created'],
            '2026-01-01 00:00:00', '2026-12-31 00:00:00',
            authorisedByUserId: 1, confirmationToken: 'wrong-token'
        );
    }

    public function test_replay_requires_an_authorising_user(): void
    {
        $replay = new EventReplay();
        $token = $replay->confirmationTokenFor('platform.audit', ['domain.order.created'], '2026-01-01 00:00:00', '2026-12-31 00:00:00', null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/authorising user/');

        $replay->execute(
            'platform.audit', ['domain.order.created'],
            '2026-01-01 00:00:00', '2026-12-31 00:00:00',
            authorisedByUserId: 0, confirmationToken: $token
        );
    }

    public function test_replay_is_not_exposed_by_any_route_or_command(): void
    {
        $exposed = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (str_contains(strtolower($route->getActionName()), 'eventreplay')) {
                $exposed[] = $route->uri();
            }
        }

        $this->assertSame([], $exposed, 'replay must not be reachable over HTTP in Phase 1B');

        $commands = glob(app_path('Console/Commands/*.php'));
        $found = [];

        foreach ($commands as $c) {
            if (str_contains(file_get_contents($c), 'EventReplay')) {
                $found[] = basename($c);
            }
        }

        $this->assertSame([], $found, 'replay must not be exposed as a console command in Phase 1B');
    }

    // ═══════════ event vs delivery state separation ══

    public function test_the_event_row_never_carries_a_per_subscriber_outcome(): void
    {
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('platform_events');

        foreach (['subscriber_key', 'subscriber_version', 'delivered_to'] as $forbidden) {
            $this->assertNotContains($forbidden, $cols,
                "platform_events must not carry per-subscriber state ('{$forbidden}') — that belongs in the delivery ledger");
        }

        // Fan-out state, however, does belong on the event.
        $this->assertContains('fanned_out_at', $cols);
        $this->assertContains('delivery_count', $cols);
    }

    public function test_phase_1a_status_column_was_not_repurposed(): void
    {
        // 1A wrote pending/dispatching/dispatched/no_subscribers/failed here and
        // its tests still assert on them. Fan-out uses its own fields instead.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('platform_events', 'status'));
        $this->assertSame('no_subscribers', \App\Core\PlatformEvents\Outbox::STATUS_NO_SUBSCRIBERS);
        $this->assertSame('dispatched', \App\Core\PlatformEvents\Outbox::STATUS_DISPATCHED);
    }
}
