<?php

namespace Tests\Feature\PlatformEvents;

use App\Core\PlatformEvents\Outbox;
use App\Core\PlatformEvents\PlatformEvent;
use App\Core\PlatformEvents\Types\DomainOrderCreated;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Architecture enforcement for the platform outbox.
 *
 * The outbox is only worth having if it has exactly one writer and one table.
 * These tests make a second writer, a second table, or an unvalidated event
 * fail the build rather than pass review.
 */
class OutboxArchitectureTest extends TestCase
{
    use IsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 1E.1: never run against a shared database.
        $this->assertIsolatedDatabase();
    }

    /** Files permitted to name the platform_events table. */
    private const TABLE_WRITERS = [
        // Phase 1A
        'app/Core/PlatformEvents/Outbox.php',
        'app/Core/PlatformEvents/OutboxDispatcher.php',
        'database/migrations/2026_07_29_190000_create_platform_events_table.php',
        // Phase 1B — fan-out and delivery are platform event infrastructure and
        // legitimately read the events table. Everything else still may not.
        'app/Core/PlatformEvents/EventFanOut.php',
        'app/Core/PlatformEvents/DeliveryWorker.php',
        'app/Core/PlatformEvents/EventReplay.php',
        'database/migrations/2026_07_29_200100_add_fanout_fields_to_platform_events.php',
        // Phase 1C — READ-ONLY. Counts rows for the health report; never writes.
        'app/Core/PlatformEvents/EventSystemHealth.php',
        // Phase 1D — READ-ONLY. Resolves symbols and compares counts; never writes.
        'app/Core/PlatformEvents/ChainVerifier.php',
        // Phase 1D — writes ONE column, delivery_count, and never inserts, deletes or
        // otherwise creates an event. The invariant this test protects is that events
        // are CREATED only by the Outbox; maintaining a derived counter on an existing
        // row does not weaken it. Enforced by
        // ReplayProjectionTest::test_the_projection_writer_only_touches_delivery_count.
        'app/Core/PlatformEvents/DeliveryProjection.php',
    ];

    private function sourceFiles(): array
    {
        $out = [];
        $roots = ['app', 'config', 'routes', 'database/migrations'];

        foreach ($roots as $root) {
            $dir = base_path($root);

            if (! is_dir($dir)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($it as $f) {
                if (! $f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }

                $rel = str_replace(base_path() . '/', '', $f->getPathname());

                // Backups are historical artefacts, not live code.
                if (str_contains($rel, '.bak')) {
                    continue;
                }

                $out[$rel] = file_get_contents($f->getPathname());
            }
        }

        return $out;
    }

    public function test_only_the_outbox_service_touches_the_outbox_table(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $rel => $src) {
            if (! str_contains($src, 'platform_events')) {
                continue;
            }

            // config/platform_events.php is the config file itself.
            if ($rel === 'config/platform_events.php') {
                continue;
            }

            // Referencing the config key is fine; naming the TABLE is not.
            if (! preg_match("/['\"]platform_events['\"]/", $src)) {
                continue;
            }

            if (! in_array($rel, self::TABLE_WRITERS, true)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders,
            "These files reference the platform_events table directly:\n  - " . implode("\n  - ", $offenders)
            . "\n\nAll writes must go through App\\Core\\PlatformEvents\\Outbox. "
            . 'An outbox with two writers has no invariants.');
    }

    public function test_no_subsystem_has_created_its_own_outbox_table(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $rel => $src) {
            if (! str_starts_with($rel, 'database/migrations/')) {
                continue;
            }

            if (preg_match('/Schema::create\(\s*[\'"]([a-z_]*(outbox|_events))[\'"]/i', $src, $m)) {
                $table = $m[1];

                if (in_array($table, ['platform_events', 'infra_events', 'infra_provider_events', 'task_events', 'ad_events', 'automation_events', 'calendar_events'], true)) {
                    continue;   // known, pre-existing subsystem tables
                }

                $offenders[] = "{$rel} creates '{$table}'";
            }
        }

        $this->assertSame([], $offenders,
            "A subsystem-local outbox defeats the point of a platform outbox:\n  - " . implode("\n  - ", $offenders));
    }

    public function test_every_typed_event_declares_a_complete_contract(): void
    {
        foreach ($this->typedEvents() as $class) {
            $this->assertNotSame('', $class::type(), "{$class} must declare a type");
            $this->assertGreaterThanOrEqual(1, $class::schemaVersion(), "{$class} must declare a schema version >= 1");
            $this->assertNotSame([], $class::requiredPayloadKeys(), "{$class} must declare required payload keys");

            $this->assertMatchesRegularExpression('/^[a-z]+(\.[a-z_]+)+$/', $class::type(),
                "{$class} type must follow the dotted, lower-case idiom");
        }
    }

    public function test_every_typed_event_rejects_an_empty_payload(): void
    {
        foreach ($this->typedEvents() as $class) {
            $threw = false;

            try {
                new $class(workspaceId: 1, payload: []);
            } catch (\InvalidArgumentException) {
                $threw = true;
            }

            $this->assertTrue($threw, "{$class} accepted an empty payload — every typed event must validate");
        }
    }

    public function test_every_typed_event_requires_a_workspace(): void
    {
        foreach ($this->typedEvents() as $class) {
            $threw = false;

            try {
                new $class(workspaceId: 0, payload: array_fill_keys($class::requiredPayloadKeys(), 1));
            } catch (\InvalidArgumentException) {
                $threw = true;
            }

            $this->assertTrue($threw, "{$class} accepted workspace_id = 0");
        }
    }

    public function test_the_blocked_key_list_is_populated_and_covers_the_obvious_secrets(): void
    {
        $blocked = array_map('strtolower', (array) config('platform_events.blocked_payload_keys', []));

        foreach (['api_key', 'secret', 'token', 'password', 'auth_code', 'epp', 'card', 'cvv', 'raw_response'] as $must) {
            $this->assertContains($must, $blocked, "'{$must}' must be on the blocked payload key list");
        }
    }

    public function test_producers_are_default_off_in_committed_configuration(): void
    {
        // Read the file, not the runtime config: a test that reads config after
        // another test has mutated it proves nothing about what ships.
        $src = file_get_contents(config_path('platform_events.php'));

        $this->assertStringContainsString("env('PLATFORM_EVENTS_ENABLED', false)", $src,
            'the master switch must default to false');
        $this->assertStringContainsString("env('PLATFORM_EVENTS_DOMAIN_ORDER_CREATED', false)", $src,
            'the shadow producer must default to false');
    }

    public function test_the_outbox_refuses_to_record_outside_a_transaction(): void
    {
        // The guard is what makes "atomic with the business state" true rather
        // than merely intended.
        $src = file_get_contents(app_path('Core/PlatformEvents/Outbox.php'));

        $this->assertStringContainsString('DB::transactionLevel() < 1', $src,
            'Outbox::record() must assert it is inside a transaction');
    }

    /** @return class-string<PlatformEvent>[] */
    private function typedEvents(): array
    {
        $dir = app_path('Core/PlatformEvents/Types');
        $out = [];

        foreach (glob($dir . '/*.php') as $f) {
            $cls = 'App\\Core\\PlatformEvents\\Types\\' . basename($f, '.php');

            if (class_exists($cls) && is_subclass_of($cls, PlatformEvent::class)) {
                $out[] = $cls;
            }
        }

        $this->assertNotSame([], $out, 'expected at least one typed event');

        return $out;
    }

    /**
     * REVISED BY PHASE 1E.
     *
     * This asserted a single registered producer, which was true for Phase 1A and is
     * the point of that milestone. Phase 1E adds domain.order.paid deliberately, so
     * the assertion becomes: the producer catalogue is EXACTLY the declared set, and
     * every entry is fully wired. That still fails on an undeclared producer sneaking
     * in — which is what the original test was protecting.
     */
    public function test_the_producer_catalogue_is_exactly_the_declared_set(): void
    {
        $expected = ['domain.order.created', 'domain.order.paid'];

        $producers = array_keys((array) config('platform_events.producers', []));
        sort($producers);

        $this->assertSame($expected, $producers,
            'a new producer must be added to this list deliberately, not discovered here');

        // Every producer must have an event class, and it must agree with its key.
        $classes = (array) config('platform_events.event_classes', []);

        foreach ($expected as $type) {
            $this->assertArrayHasKey($type, $classes, "{$type} has no declared event class");
            $this->assertTrue(class_exists($classes[$type]));
            $this->assertSame($type, $classes[$type]::type(),
                "{$type}: the map key and the class disagree");
        }
    }

    public function test_phase_1a_has_no_production_subscribers(): void
    {
        // Honesty guard. If a subscriber is registered without authorisation,
        // this fails and the milestone's claims stop being true.
        $dispatcher = new \App\Core\PlatformEvents\OutboxDispatcher();

        $this->assertSame(0, $dispatcher->handlerCountFor('domain.order.created'),
            'Phase 1A must have zero production subscribers — audit, notification, '
            . 'memory and analytics integration is Phase 1B and is NOT implemented');
    }

    public function test_outbox_status_vocabulary_distinguishes_delivery_from_absence_of_subscribers(): void
    {
        $this->assertSame('no_subscribers', Outbox::STATUS_NO_SUBSCRIBERS);
        $this->assertNotSame(Outbox::STATUS_DISPATCHED, Outbox::STATUS_NO_SUBSCRIBERS,
            'an event nothing consumed must never be recorded as dispatched');
    }
}
