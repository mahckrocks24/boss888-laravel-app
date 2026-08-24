<?php

namespace Tests\Feature\Infrastructure\Email;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * INFRA888 · E1-H — MIGRATION REPLAY AND ROLLBACK PROOF.
 *
 * Deliberately does NOT use RefreshDatabase. This class drives the migrator
 * itself, because what it proves is that the migrator works — wrapping it in a
 * trait that has already migrated would prove nothing.
 *
 * WHY A CLEAN-INSTALL PROOF IS NOT OPTIONAL HERE
 * This project has already shipped a migration chain that could not replay from
 * zero: the failures were MySQL's 3072-byte key limit and its 64-character
 * identifier limit, both invisible until a genuine clean install was attempted.
 * The Business Email family adds six tables, four composite unique keys — one of
 * them on a 320-octet column — and six named foreign keys. Every one of those is
 * a candidate for the same class of defect.
 */
class BusinessEmailMigrationReplayTest extends TestCase
{
    /** In creation order. Rollback runs this reversed. */
    private const FAMILY = [
        '2026_08_04_140501_create_email_domains_table',
        '2026_08_04_140502_create_email_mailboxes_table',
        '2026_08_04_140503_create_email_aliases_table',
        '2026_08_04_140504_create_email_forwarders_table',
        '2026_08_04_140505_create_email_catchall_table',
        '2026_08_04_140506_create_email_usage_table',
    ];

    private const TABLES = [
        'email_domains', 'email_mailboxes', 'email_aliases',
        'email_forwarders', 'email_catchall', 'email_usage',
    ];

    public function test_the_whole_migration_chain_replays_from_zero(): void
    {
        $exit = Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertSame(0, $exit, "migrate:fresh failed:\n" . Artisan::output());

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} absent after a clean install.");
        }
    }

    public function test_every_business_email_migration_is_recorded_as_run(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $ran = DB::table('migrations')->pluck('migration')->all();

        foreach (self::FAMILY as $migration) {
            $this->assertContains($migration, $ran, "{$migration} did not run on a clean install.");
        }
    }

    public function test_the_migration_family_has_unique_timestamps(): void
    {
        $all = array_map(
            fn (string $path) => basename($path, '.php'),
            (array) glob(database_path('migrations/*.php'))
        );

        $prefixes = [];

        foreach (self::FAMILY as $migration) {
            $prefix = substr($migration, 0, 17); // yyyy_mm_dd_hhmmss

            $collisions = array_values(array_filter(
                $all,
                fn (string $name) => str_starts_with($name, $prefix) && $name !== $migration
            ));

            $this->assertSame(
                [],
                $collisions,
                "{$migration} collides with: " . implode(', ', $collisions)
                . '. Two migrations sharing a timestamp have an undefined order between them.'
            );

            $this->assertArrayNotHasKey($prefix, $prefixes, "Duplicate timestamp inside the family: {$prefix}");
            $prefixes[$prefix] = true;
        }
    }

    public function test_the_family_rolls_back_and_reruns_cleanly(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $files = [];

        foreach (self::FAMILY as $migration) {
            $path = database_path("migrations/{$migration}.php");
            $this->assertFileExists($path);
            $files[] = $path;
        }

        // DOWN, in reverse dependency order. If a foreign key were declared in
        // the wrong direction, or an index name collided, this is where it
        // surfaces rather than in production.
        foreach (array_reverse($files) as $path) {
            $migration = require $path;
            $migration->down();
        }

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} survived its own down().");
        }

        // UP again, from the same source.
        foreach ($files as $path) {
            $migration = require $path;
            $migration->up();
        }

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} did not come back after re-running up().");
        }
    }

    public function test_migrations_are_idempotent_when_the_tables_already_exist(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        // Every migration in this family guards on Schema::hasTable. Running
        // up() a second time against an existing schema must be a no-op rather
        // than a "table already exists" failure — that is what makes a partial
        // deploy recoverable by re-running rather than by hand-editing.
        foreach (self::FAMILY as $migration) {
            $instance = require database_path("migrations/{$migration}.php");
            $instance->up();
        }

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertSame(
            0,
            (int) DB::table('email_domains')->count(),
            'A re-run must not have altered data.'
        );
    }

    public function test_no_business_email_migration_touches_a_table_it_does_not_own(): void
    {
        foreach (self::FAMILY as $migration) {
            $source = (string) file_get_contents(database_path("migrations/{$migration}.php"));

            preg_match_all("/Schema::(create|table|drop|dropIfExists)\(\s*'([a-z_]+)'/", $source, $matches);

            foreach ($matches[2] as $table) {
                $this->assertContains(
                    $table,
                    self::TABLES,
                    "{$migration} modifies '{$table}', which belongs to another work package. E1 creates its "
                    . 'own tables and alters nothing that already existed.'
                );
            }
        }
    }
}
