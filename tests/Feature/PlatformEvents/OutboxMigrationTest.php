<?php

namespace Tests\Feature\PlatformEvents;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/**
 * Migration up/down on an ISOLATED in-memory database.
 *
 * Deliberately not run against the shared test database: a rollback there would
 * disturb every other suite, and the point is to prove the migration is
 * reversible in isolation, not to reverse anything real.
 */
class OutboxMigrationTest extends TestCase
{
    use IsolatedDatabase;

    private const CONN = 'outbox_migration_probe';

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

        // A single connection instance so :memory: persists across queries.
        DB::purge(self::CONN);
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_07_29_190000_create_platform_events_table.php');
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

    public function test_migration_runs_up_and_creates_the_table(): void
    {
        $this->withConnection(function () {
            $this->assertFalse(Schema::connection(self::CONN)->hasTable('platform_events'));

            $this->migration()->up();

            $this->assertTrue(Schema::connection(self::CONN)->hasTable('platform_events'));
            $this->assertIsolatedFrom('platform_events');
        });
    }

    public function test_migration_creates_every_declared_column(): void
    {
        $this->withConnection(function () {
            $this->migration()->up();

            foreach ([
                'id', 'event_id', 'workspace_id', 'event_type', 'schema_version', 'capability_key',
                'actor_type', 'actor_id', 'subject_type', 'subject_id', 'correlation_id', 'causation_id',
                'payload_json', 'sensitivity', 'status', 'occurred_at', 'recorded_at',
                'next_attempt_at', 'locked_at', 'dispatched_at', 'attempt_count', 'last_error',
            ] as $col) {
                $this->assertTrue(
                    Schema::connection(self::CONN)->hasColumn('platform_events', $col),
                    "column '{$col}' is missing after migrating up"
                );
            }
        });
    }

    public function test_migration_rolls_back_cleanly(): void
    {
        $this->withConnection(function () {
            $this->migration()->up();
            $this->assertTrue(Schema::connection(self::CONN)->hasTable('platform_events'));

            $this->migration()->down();
            $this->assertFalse(Schema::connection(self::CONN)->hasTable('platform_events'),
                'down() must drop the table cleanly');
        });
    }

    public function test_migration_is_re_runnable_after_rollback(): void
    {
        $this->withConnection(function () {
            $this->migration()->up();
            $this->migration()->down();
            $this->migration()->up();

            $this->assertTrue(Schema::connection(self::CONN)->hasTable('platform_events'));
        });
    }

    public function test_event_id_uniqueness_is_enforced_by_the_schema(): void
    {
        $this->withConnection(function () {
            $this->migration()->up();

            $row = [
                'event_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'workspace_id' => 1, 'event_type' => 't', 'schema_version' => 1,
                'actor_type' => 'system', 'subject_type' => 's', 'subject_id' => '1',
                'correlation_id' => 'aaaaaaaa-bbbb-cccc-dddd-ffffffffffff',
                'payload_json' => '{}', 'sensitivity' => 'internal', 'status' => 'pending',
                'occurred_at' => '2026-07-29 00:00:00', 'recorded_at' => '2026-07-29 00:00:00',
                'attempt_count' => 0,
            ];

            DB::connection(self::CONN)->table('platform_events')->insert($row);

            $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
            DB::connection(self::CONN)->table('platform_events')->insert($row);
        });
    }
}
