<?php

namespace Tests\Feature\PlatformEvents;

use App\Console\Commands\PlatformEventsProcessCommand;
use App\Core\PlatformEvents\ChainVerifier;
use App\Core\PlatformEvents\EventSystemHealth;
use App\Core\PlatformEvents\Outbox;
use App\Core\PlatformEvents\SubscriberRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Phase 1D — the 10:20:05 production failure as a permanent test case.
 *
 * On 2026-07-30 the scheduled command died with
 *     Call to undefined method ...SubscriberRegistry::active()
 * after a patch removed the method and left one call site behind. Every file passed
 * `php -l`, because an undefined static method is not a syntax error.
 *
 * These tests prove the verification gate catches that class of defect, and that an
 * unexpected processing exception can no longer be normalised into a healthy-looking
 * system by the next clean five-minute cycle.
 */
class DeploymentVerificationTest extends TestCase
{
    use IsolatedDatabase;

    private int $ws = 997001;
    private int $userId = 997901;

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

        Cache::forget(PlatformEventsProcessCommand::FAILURE_STATE_KEY);
        $this->cleanup();
        $this->seedTenants();
    }

    protected function tearDown(): void
    {
        Cache::forget(PlatformEventsProcessCommand::FAILURE_STATE_KEY);
        $this->cleanup();
        DB::table('users')->where('id', $this->userId)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        parent::tearDown();
    }

    private function seedTenants(): void
    {
        if (! DB::table('users')->where('id', $this->userId)->exists()) {
            DB::table('users')->insert([
                'id' => $this->userId, 'name' => 'Phase1D Actor',
                'email' => 'phase1d-' . $this->userId . '@example.invalid',
                'password' => bcrypt('not-a-real-password'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! DB::table('workspaces')->where('id', $this->ws)->exists()) {
            DB::table('workspaces')->insert([
                'id' => $this->ws, 'name' => 'Phase1D WS', 'slug' => 'phase1d-ws-' . $this->ws,
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

    private function recordEvent(): string
    {
        $eventId = (string) Str::uuid();

        DB::table('platform_events')->insert([
            'event_id' => $eventId, 'workspace_id' => $this->ws,
            'event_type' => 'domain.order.created', 'schema_version' => 1,
            'capability_key' => 'domain.register', 'actor_type' => 'user', 'actor_id' => $this->userId,
            'subject_type' => 'domain_order', 'subject_id' => '970001',
            'correlation_id' => (string) Str::uuid(),
            'payload_json' => json_encode([
                'order_id' => 970001, 'currency' => 'USD', 'subtotal_minor' => 1817,
                'item_count' => 1, 'domains' => ['verify-test.com'],
            ]),
            'sensitivity' => 'internal', 'status' => Outbox::STATUS_PENDING,
            'occurred_at' => '2026-07-30 10:00:00', 'recorded_at' => '2026-07-30 10:00:00',
            'attempt_count' => 0, 'delivery_count' => 0,
        ]);

        return $eventId;
    }

    private function check(array $result, string $name): array
    {
        foreach ($result['checks'] as $c) {
            if ($c['name'] === $name) {
                return $c;
            }
        }

        $this->fail("verifier produced no check named '{$name}'");
    }

    // ══════════════ the gate passes on the real chain ══

    public function test_the_real_chain_passes_verification(): void
    {
        $r = app(ChainVerifier::class)->verify();

        $this->assertTrue($r['passed'],
            'the live chain must verify; failures: ' . json_encode($r['summary']['failed_names']));
    }

    // ══════════════ 1. deleted registry method reference ══

    public function test_a_deleted_registry_method_reference_fails_verification(): void
    {
        $broken = <<<'PHP'
        <?php
        namespace App\Core\PlatformEvents;
        class Pretend {
            public function go(): array { return SubscriberRegistry::active(); }
        }
        PHP;

        $r = app(ChainVerifier::class)->verify(['pretend.php' => $broken]);

        $this->assertFalse($r['passed'], 'a call to the deleted method must fail the gate');

        $symbols = $this->check($r, 'chain_symbols_resolve');
        $this->assertFalse($symbols['ok']);
        $this->assertStringContainsString('SubscriberRegistry::active() does not exist', $symbols['detail']);

        $deleted = $this->check($r, 'deleted_registry_api_unreferenced');
        $this->assertFalse($deleted['ok'], 'the removed-API check must also flag it');
    }

    /** A mention in a comment or string is NOT a call site and must not fail. */
    public function test_a_mention_in_a_comment_or_string_does_not_fail_verification(): void
    {
        $harmless = <<<'PHP'
        <?php
        namespace App\Core\PlatformEvents;
        class Pretend {
            /** Historical note: SubscriberRegistry::active() was removed in 1C.1. */
            public function go(): string { return 'SubscriberRegistry::active('; }
        }
        PHP;

        $r = app(ChainVerifier::class)->verify(['harmless.php' => $harmless]);

        $this->assertTrue($this->check($r, 'chain_symbols_resolve')['ok'],
            'prose and string literals are not call sites');
        $this->assertTrue($this->check($r, 'deleted_registry_api_unreferenced')['ok']);
    }

    // ══════════════ 2. missing subscriber handler class ══

    public function test_a_missing_subscriber_handler_class_fails_verification(): void
    {
        config(['platform_events.subscriber_handlers' => [
            'platform.audit' => ['App\\Nope\\NotARealSubscriber', 'handle'],
        ]]);

        $r = app(ChainVerifier::class)->verify();

        $c = $this->check($r, 'subscriber_handlers_callable');
        $this->assertFalse($c['ok']);
        $this->assertStringContainsString('does not exist', $c['detail']);
        $this->assertFalse($r['passed']);
    }

    public function test_an_unconfigured_subscriber_handler_fails_verification(): void
    {
        config(['platform_events.subscriber_handlers' => []]);

        $r = app(ChainVerifier::class)->verify();

        $this->assertFalse($this->check($r, 'subscriber_handlers_configured')['ok']);
        $this->assertFalse($r['passed']);
    }

    // ══════════════ 3. non-callable subscriber handler ══

    public function test_a_non_callable_subscriber_handler_fails_verification(): void
    {
        config(['platform_events.subscriber_handlers' => [
            'platform.audit' => [\App\Core\PlatformEvents\Subscribers\AuditSubscriber::class, 'notAMethod'],
        ]]);

        $r = app(ChainVerifier::class)->verify();

        $c = $this->check($r, 'subscriber_handlers_callable');
        $this->assertFalse($c['ok']);
        $this->assertStringContainsString('notAMethod', $c['detail']);
    }

    /** And the worker refuses to run it, rather than silently doing nothing. */
    public function test_the_worker_refuses_a_handler_that_is_not_callable(): void
    {
        config(['platform_events.subscriber_handlers' => [
            'platform.audit' => [\App\Core\PlatformEvents\Subscribers\AuditSubscriber::class, 'notAMethod'],
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/notAMethod\(\) does not exist/');

        SubscriberRegistry::handlerCallable('platform.audit');
    }

    // ══════════════ 4. missing event class ══

    public function test_a_missing_event_class_fails_verification(): void
    {
        config(['platform_events.event_classes' => [
            'domain.order.created' => 'App\\Nope\\NotAnEvent',
        ]]);

        $r = app(ChainVerifier::class)->verify();

        $c = $this->check($r, 'event_classes_valid');
        $this->assertFalse($c['ok']);
        $this->assertStringContainsString('does not exist', $c['detail']);
    }

    public function test_an_event_class_whose_type_disagrees_fails_verification(): void
    {
        // A real PlatformEvent subclass mapped under the WRONG type key.
        config(['platform_events.event_classes' => [
            'domain.order.mistyped' => \App\Core\PlatformEvents\Types\DomainOrderCreated::class,
            'domain.order.created' => \App\Core\PlatformEvents\Types\DomainOrderCreated::class,
        ]]);

        $r = app(ChainVerifier::class)->verify();

        $c = $this->check($r, 'event_classes_valid');
        $this->assertFalse($c['ok']);
        $this->assertStringContainsString('the map key and the class disagree', $c['detail']);
    }

    /** The map is load-bearing: Outbox refuses an undeclared event type. */
    public function test_the_outbox_refuses_an_event_type_with_no_declared_class(): void
    {
        config(['platform_events.event_classes' => []]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no declared class/');

        DB::transaction(function () {
            app(Outbox::class)->record(new \App\Core\PlatformEvents\Types\DomainOrderCreated(
                workspaceId: $this->ws,
                payload: [
                    'order_id' => 970002, 'currency' => 'USD', 'subtotal_minor' => 100,
                    'item_count' => 1, 'domains' => ['declared.example'],
                ],
                actorType: \App\Core\PlatformEvents\Types\DomainOrderCreated::ACTOR_SYSTEM,
                actorId: null,
                capabilityKey: 'domain.register',
            ));
        });
    }

    // ══════════════ 5. missing referenced constant ══

    public function test_a_missing_referenced_constant_fails_verification(): void
    {
        $broken = <<<'PHP'
        <?php
        namespace App\Core\PlatformEvents;
        class Pretend {
            public function go(): string { return SubscriberRegistry::STATE_IMAGINARY; }
        }
        PHP;

        $r = app(ChainVerifier::class)->verify(['pretend-const.php' => $broken]);

        $c = $this->check($r, 'chain_symbols_resolve');
        $this->assertFalse($c['ok']);
        $this->assertStringContainsString('STATE_IMAGINARY does not exist', $c['detail']);
    }

    public function test_all_four_state_constants_are_verified_present_and_distinct(): void
    {
        $c = $this->check(app(ChainVerifier::class)->verify(), 'subscriber_state_constants');

        $this->assertTrue($c['ok']);
        foreach (['active', 'execution_paused', 'disabled_for_future_eligibility', 'retired'] as $v) {
            $this->assertStringContainsString($v, $c['detail']);
        }
    }

    // ══════════════ 6 + 7. command and scheduler ══

    public function test_the_scheduled_command_resolves(): void
    {
        $this->assertInstanceOf(PlatformEventsProcessCommand::class, app(PlatformEventsProcessCommand::class));

        $r = app(ChainVerifier::class)->verify();
        $this->assertTrue($this->check($r, 'command_resolvable')['ok']);
        $this->assertTrue($this->check($r, 'command_registered')['ok']);
    }

    public function test_the_scheduler_must_point_at_an_existing_command(): void
    {
        $good = new Schedule();
        $good->command('platform-events:process');
        app()->instance(Schedule::class, $good);

        $this->assertTrue(
            $this->check(app(ChainVerifier::class)->verify(), 'scheduler_points_at_existing_command')['ok'],
            'a schedule naming the real command must pass'
        );

        $bad = new Schedule();
        $bad->command('platform-events:this-command-does-not-exist');
        app()->instance(Schedule::class, $bad);

        $c = $this->check(app(ChainVerifier::class)->verify(), 'scheduler_points_at_existing_command');
        $this->assertFalse($c['ok'], 'a schedule naming a non-existent command must fail');
        $this->assertStringContainsString('unknown', $c['detail']);
    }

    // ══════════════ the command is a no-op when it should be ══

    public function test_the_command_is_a_noop_with_all_flags_off(): void
    {
        $this->recordEvent();

        config([
            'platform_events.enabled' => false,
            'platform_events.fanout.enabled' => false,
            'platform_events.delivery_worker.enabled' => false,
            'platform_events.subscribers' => ['platform.audit' => false],
        ]);

        $this->artisan('platform-events:process')->assertExitCode(0);

        $this->assertSame(0, DB::table('platform_event_deliveries')
            ->where('workspace_id', $this->ws)->count());
        $this->assertNull(DB::table('platform_events')->where('workspace_id', $this->ws)->value('fanned_out_at'));
    }

    public function test_the_command_is_a_noop_with_an_empty_queue_of_work(): void
    {
        $this->assertSame(0, DB::table('platform_events')->where('workspace_id', $this->ws)->count());

        $this->artisan('platform-events:process')->assertExitCode(0);

        $this->assertSame(0, DB::table('platform_event_deliveries')->where('workspace_id', $this->ws)->count());
    }

    // ══════════════ 8, 9, 10. unexpected exception semantics ══

    /**
     * Force a genuine unexpected exception through the real code path by pointing the
     * default database connection at a connection that is not configured. The cache
     * store is `array` under phpunit, so the lock is still obtainable and the command
     * reaches its processing block — which is the path under test.
     */
    private function runWithBrokenDatabase(): int
    {
        $previous = Config::get('database.default');
        Config::set('database.default', 'no_such_connection_for_phase_1d');

        try {
            return Artisan::call('platform-events:process');
        } finally {
            Config::set('database.default', $previous);
            \Illuminate\Support\Facades\Facade::clearResolvedInstance('db');
            \Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');
        }
    }

    public function test_an_unexpected_processing_exception_returns_non_zero(): void
    {
        $exit = $this->runWithBrokenDatabase();

        $this->assertNotSame(0, $exit, 'a thrown pass must not report success');
    }

    public function test_an_unexpected_processing_exception_degrades_health(): void
    {
        $before = app(EventSystemHealth::class)->report();
        $this->assertSame(0, $before['processing_failures']['count']);

        $this->runWithBrokenDatabase();

        $after = app(EventSystemHealth::class)->report();

        $this->assertSame(1, $after['processing_failures']['count'],
            'the failure must be recorded where health can see it');
        $this->assertNotNull($after['processing_failures']['last_at']);
        $this->assertNotNull($after['processing_failures']['last_error']);
        $this->assertSame(EventSystemHealth::CRITICAL, $after['severity'],
            'a recent unacknowledged failure is CRITICAL');

        $messages = implode(' | ', array_column($after['findings'], 'message'));
        $this->assertStringContainsString('unacknowledged processing failure', $messages);
    }

    public function test_a_later_successful_pass_does_not_erase_the_failure(): void
    {
        $this->runWithBrokenDatabase();

        // A clean pass, exactly as the five-minute schedule would run next.
        $this->artisan('platform-events:process')->assertExitCode(0);

        $h = app(EventSystemHealth::class)->report();

        $this->assertSame(1, $h['processing_failures']['count'],
            'THE 10:20:05 LESSON: a clean cycle must not make a real failure disappear');
        $this->assertSame(EventSystemHealth::CRITICAL, $h['severity']);
    }

    public function test_the_failure_state_is_cleared_only_by_explicit_acknowledgement(): void
    {
        $this->runWithBrokenDatabase();
        $this->assertSame(1, app(EventSystemHealth::class)->report()['processing_failures']['count']);

        $this->artisan('platform-events:process --ack-failures')->assertExitCode(0);

        $h = app(EventSystemHealth::class)->report();
        $this->assertSame(0, $h['processing_failures']['count']);
        $this->assertNotSame(EventSystemHealth::CRITICAL, $h['severity']);
    }

    public function test_repeated_failures_are_counted(): void
    {
        $this->runWithBrokenDatabase();
        $this->runWithBrokenDatabase();
        $this->runWithBrokenDatabase();

        $p = app(EventSystemHealth::class)->report()['processing_failures'];

        $this->assertSame(3, $p['count'], 'the operator needs the count, not just the last one');
        $this->assertNotNull($p['first_at']);
    }

    public function test_an_unexpected_exception_performs_no_partial_stamping(): void
    {
        $eventId = $this->recordEvent();

        $this->runWithBrokenDatabase();

        $event = DB::table('platform_events')->where('event_id', $eventId)->first();

        $this->assertNull($event->fanned_out_at,
            'a failed pass must not leave the event marked as fanned out');
        $this->assertSame(0, (int) $event->delivery_count);
        $this->assertSame(0, DB::table('platform_event_deliveries')->where('event_id', $eventId)->count());
        $this->assertSame(0, DB::table('platform_events')
            ->where('workspace_id', $this->ws)->whereNotNull('fanned_out_at')->count());
    }

    public function test_an_unexpected_exception_deletes_no_evidence(): void
    {
        $eventId = $this->recordEvent();
        $eventsBefore = DB::table('platform_events')->count();
        $deliveriesBefore = DB::table('platform_event_deliveries')->count();
        $auditBefore = DB::table('audit_logs')->count();

        $this->runWithBrokenDatabase();

        $this->assertSame($eventsBefore, DB::table('platform_events')->count());
        $this->assertSame($deliveriesBefore, DB::table('platform_event_deliveries')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertNotNull(DB::table('platform_events')->where('event_id', $eventId)->first());
    }

    public function test_the_failure_record_carries_no_payload_or_secret(): void
    {
        $this->runWithBrokenDatabase();

        $rec = Cache::get(PlatformEventsProcessCommand::FAILURE_STATE_KEY);

        $this->assertIsArray($rec);
        $serialised = json_encode($rec);

        foreach (['payload', 'password', 'api_key', 'secret', 'token', 'subtotal_minor', 'domains'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $serialised,
                "the failure record must not carry '{$forbidden}'");
        }
    }

    // ══════════════ the gate is read-only ══

    public function test_verification_writes_nothing(): void
    {
        $eventId = $this->recordEvent();

        $events = DB::table('platform_events')->count();
        $deliveries = DB::table('platform_event_deliveries')->count();
        $audit = DB::table('audit_logs')->count();
        $stamp = DB::table('platform_events')->where('event_id', $eventId)->value('fanned_out_at');

        app(ChainVerifier::class)->verify();
        $this->artisan('platform-events:verify')->assertExitCode(0);

        $this->assertSame($events, DB::table('platform_events')->count());
        $this->assertSame($deliveries, DB::table('platform_event_deliveries')->count());
        $this->assertSame($audit, DB::table('audit_logs')->count());
        $this->assertSame($stamp, DB::table('platform_events')->where('event_id', $eventId)->value('fanned_out_at'));
    }

    public function test_the_verify_command_never_touches_the_processing_lock(): void
    {
        $src = file_get_contents(app_path('Core/PlatformEvents/ChainVerifier.php'));

        $this->assertStringContainsString("'platform-events:verify-probe'", $src,
            'the probe must use its own key');
        $this->assertStringNotContainsString("Cache::lock('platform-events:process'", $src,
            'verification must never contend for the real processing lock');
    }

    public function test_the_verifier_contains_no_write_operations(): void
    {
        $src = file_get_contents(app_path('Core/PlatformEvents/ChainVerifier.php'));

        foreach (['->insert(', '->update(', '->delete(', '->truncate(', 'DB::statement('] as $mutation) {
            $this->assertStringNotContainsString($mutation, $src,
                "ChainVerifier is read-only but contains '{$mutation}'");
        }
    }
}
