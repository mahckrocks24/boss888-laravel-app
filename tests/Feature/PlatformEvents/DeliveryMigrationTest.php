<?php

namespace Tests\Feature\PlatformEvents;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Migration up/down/re-run for both Phase 1B migrations, on an ISOLATED
 * in-memory connection. The shared test database is never rolled back.
 */
class DeliveryMigrationTest extends TestCase
{
    use IsolatedDatabase;

    private const CONN = 'delivery_migration_probe';

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 1E.1: never run against a shared database.
        $this->assertIsolatedDatabase();

        Config::set('database.connections.' . self::CONN, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge(self::CONN);
    }

    private function eventsMigration(): object
    {
        return require base_path('database/migrations/2026_07_29_190000_create_platform_events_table.php');
    }

    private function deliveriesMigration(): object
    {
        return require base_path('database/migrations/2026_07_29_200000_create_platform_event_deliveries_table.php');
    }

    private function fanoutMigration(): object
    {
        return require base_path('database/migrations/2026_07_29_200100_add_fanout_fields_to_platform_events.php');
    }

    private function withConnection(callable $fn): mixed
    {
        $previous = Config::get('database.default');

        Config::set('database.default', self::CONN);

        // CRITICAL: the migration files use the bare Schema:: facade, and the
        // facade caches its resolved root. Without clearing it, Schema::create()
        // and Schema::dropIfExists() keep targeting the PREVIOUS connection —
        // which meant these "isolated" tests were creating and dropping tables
        // in the shared test database. Discovered 2026-07-30.
        Facade::clearResolvedInstance('db');
        Facade::clearResolvedInstance('db.schema');

        try {
            return $fn();
        } finally {
            Config::set('database.default', $previous);
            Facade::clearResolvedInstance('db');
            Facade::clearResolvedInstance('db.schema');
        }
    }

    /**
     * Guard against the exact regression described above: if the probe
     * connection is not really isolated, this fails loudly instead of quietly
     * dropping a table other suites depend on.
     */
    private function assertIsolatedFrom(string $table): void
    {
        $default = Config::get('database.default');

        $this->assertSame(self::CONN, $default, 'the probe connection must be the default inside withConnection()');

        $onProbe = Schema::connection(self::CONN)->hasTable($table);
        $this->assertTrue($onProbe, "the probe connection should hold '{$table}'");
    }

    public function test_delivery_migration_creates_the_table_with_every_column(): void
    {
        $this->withConnection(function () {
            $this->deliveriesMigration()->up();

            $this->assertTrue(Schema::connection(self::CONN)->hasTable('platform_event_deliveries'));
            $this->assertIsolatedFrom('platform_event_deliveries');

            foreach ([
                'id', 'event_id', 'subscriber_key', 'subscriber_version', 'workspace_id', 'status',
                'attempt_count', 'next_attempt_at', 'claimed_at', 'delivered_at', 'failed_at',
                'last_error', 'skip_reason', 'created_at', 'updated_at',
            ] as $col) {
                $this->assertTrue(Schema::connection(self::CONN)->hasColumn('platform_event_deliveries', $col),
                    "missing column '{$col}'");
            }
        });
    }

    public function test_delivery_identity_uniqueness_is_enforced_by_the_schema(): void
    {
        $this->withConnection(function () {
            $this->deliveriesMigration()->up();

            $row = [
                'event_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'subscriber_key' => 'platform.audit', 'subscriber_version' => 1,
                'workspace_id' => 1, 'status' => 'pending', 'attempt_count' => 0,
                'created_at' => '2026-07-29 00:00:00', 'updated_at' => '2026-07-29 00:00:00',
            ];

            DB::connection(self::CONN)->table('platform_event_deliveries')->insert($row);

            $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
            DB::connection(self::CONN)->table('platform_event_deliveries')->insert($row);
        });
    }

    public function test_a_different_subscriber_version_is_permitted_by_the_schema(): void
    {
        $this->withConnection(function () {
            $this->deliveriesMigration()->up();

            $base = [
                'event_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'subscriber_key' => 'platform.audit',
                'workspace_id' => 1, 'status' => 'pending', 'attempt_count' => 0,
                'created_at' => '2026-07-29 00:00:00', 'updated_at' => '2026-07-29 00:00:00',
            ];

            DB::connection(self::CONN)->table('platform_event_deliveries')->insert($base + ['subscriber_version' => 1]);
            DB::connection(self::CONN)->table('platform_event_deliveries')->insert($base + ['subscriber_version' => 2]);

            $this->assertSame(2, DB::connection(self::CONN)->table('platform_event_deliveries')->count());
        });
    }

    public function test_delivery_migration_rolls_back_and_re_runs(): void
    {
        $this->withConnection(function () {
            $this->deliveriesMigration()->up();
            $this->deliveriesMigration()->down();
            $this->assertFalse(Schema::connection(self::CONN)->hasTable('platform_event_deliveries'));

            $this->deliveriesMigration()->up();
            $this->assertTrue(Schema::connection(self::CONN)->hasTable('platform_event_deliveries'));
        });
    }

    public function test_fanout_migration_adds_fields_additively_and_reverses(): void
    {
        $this->withConnection(function () {
            $this->eventsMigration()->up();

            $this->assertFalse(Schema::connection(self::CONN)->hasColumn('platform_events', 'fanned_out_at'));

            $this->fanoutMigration()->up();

            $this->assertTrue(Schema::connection(self::CONN)->hasColumn('platform_events', 'fanned_out_at'));
            $this->assertTrue(Schema::connection(self::CONN)->hasColumn('platform_events', 'delivery_count'));
            // Additive: the 1A column survives untouched.
            $this->assertTrue(Schema::connection(self::CONN)->hasColumn('platform_events', 'status'));

            $this->fanoutMigration()->down();

            $this->assertFalse(Schema::connection(self::CONN)->hasColumn('platform_events', 'fanned_out_at'));
            $this->assertTrue(Schema::connection(self::CONN)->hasColumn('platform_events', 'status'),
                'rolling back fan-out must not disturb the Phase 1A schema');
        });
    }
}
