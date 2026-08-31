<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INC-0006 — an explicit lifecycle for workspaces.
 *
 * The defect that made every website its own workspace also left behind workspaces that are not businesses:
 * fourteen of them hold no website at all, because a build was started and abandoned. They are not customers,
 * they are not deletable (their rows are history, and some carry credit ledger entries that must never be
 * rewritten), and until now nothing in the schema could say what they are.
 *
 * Three states, and no others:
 *
 *   active                 a real business workspace — the only state a customer surface shows
 *   archived_failed_build  a workspace the provisioner created for a build that never produced a website.
 *                          Its rows stay exactly as they are; it simply stops appearing and stops accepting
 *                          new work.
 *   qa                     a deliberate test fixture. Preserved and reachable through authorised QA and admin
 *                          paths, never shown as a customer's business.
 *
 * archived_at / archived_reason record the provenance of the decision, so an archival can always be explained
 * and reversed. Nothing is deleted by this migration, and no row moves between workspaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        if (! Schema::hasColumn('workspaces', 'lifecycle_state')) {
            DB::statement("ALTER TABLE `workspaces`
                           ADD COLUMN `lifecycle_state` ENUM('active','archived_failed_build','qa')
                               NOT NULL DEFAULT 'active' AFTER `slug`,
                           ADD COLUMN `archived_at` TIMESTAMP NULL DEFAULT NULL AFTER `lifecycle_state`,
                           ADD COLUMN `archived_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `archived_at`");
        }

        // Customer surfaces filter on this constantly; it is low-cardinality but the tables it joins are large.
        $hasIndex = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'workspaces')
            ->where('INDEX_NAME', 'workspaces_lifecycle_state_index')
            ->exists();

        if (! $hasIndex) {
            DB::statement('ALTER TABLE `workspaces` ADD INDEX `workspaces_lifecycle_state_index` (`lifecycle_state`)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('workspaces', 'lifecycle_state')) {
            return;
        }

        DB::statement('ALTER TABLE `workspaces` DROP INDEX `workspaces_lifecycle_state_index`');
        DB::statement('ALTER TABLE `workspaces`
                       DROP COLUMN `lifecycle_state`,
                       DROP COLUMN `archived_at`,
                       DROP COLUMN `archived_reason`');
    }
};
