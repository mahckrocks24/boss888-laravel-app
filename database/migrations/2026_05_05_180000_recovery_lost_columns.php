<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery migration — captures schema state changes applied to staging on
 * 2026-05-05 that were NOT covered by any of the 92 recovered migrations.
 *
 * Background: between the Apr 25 baseline tarball and the May 4 droplet
 * destruction, several patches added columns to existing tables. Those
 * patch migrations were lost. App code references the columns; the recovery
 * archive's migrations do not create them. Fixed at deploy time via:
 *   - inline prepend to 2026_04_19_050000_add_media_library_columns.php
 *     (added media.is_platform_asset + media.tags before the dependent block)
 *   - manual ALTER TABLE statements run against staging directly
 *
 * This migration documents and reproduces all of those changes so a fresh
 * deploy on any new environment can rebuild the schema without manual ALTERs.
 *
 * IDEMPOTENT — every change is guarded by hasColumn / hasIndex checks. Safe
 * to run repeatedly. On the staging droplet (where these changes already
 * exist), this migration runs as a no-op.
 *
 * DOWN — reverses the column additions and indexes. Deliberately does NOT
 * revert agents.role to NOT NULL (existing rows may have NULL, which would
 * cause the ALTER to fail).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. media.is_platform_asset + media.tags ───────────────────────────
        // Referenced by MediaController, AdminMediaController, AdminContentController,
        // MediaService, SmokeTestCommand. Also the anchor for ->after('is_platform_asset')
        // and ->after('tags') in 2026_04_19_050000_add_media_library_columns.php.
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'is_platform_asset')) {
                $table->boolean('is_platform_asset')->default(false)->after('source')->index();
            }
            if (!Schema::hasColumn('media', 'tags')) {
                $table->json('tags')->nullable()->after('category');
            }
        });

        // ── 2. tasks.plan_task_id (FK to plan_tasks.id) ───────────────────────
        // Listed in Task::$fillable; surfaced by the model.fillable-vs-DB audit.
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'plan_task_id')) {
                $table->unsignedBigInteger('plan_task_id')->nullable();
            }
        });
        if (Schema::hasColumn('tasks', 'plan_task_id')
            && !Schema::hasIndex('tasks', 'tasks_plan_task_id_index')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->index('plan_task_id', 'tasks_plan_task_id_index');
            });
        }

        // ── 3. websites.custom_domain + websites.domain_verified ──────────────
        // Queried by PublishedSiteMiddleware on every HTTP request to gate
        // custom-domain dispatch. Without these columns, every request 500s.
        Schema::table('websites', function (Blueprint $table) {
            if (!Schema::hasColumn('websites', 'custom_domain')) {
                $table->string('custom_domain', 255)->nullable()->after('subdomain');
            }
            if (!Schema::hasColumn('websites', 'domain_verified')) {
                $table->boolean('domain_verified')->default(false)->after('custom_domain');
            }
        });
        if (Schema::hasColumn('websites', 'custom_domain')
            && !Schema::hasIndex('websites', 'websites_custom_domain_index')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->index('custom_domain', 'websites_custom_domain_index');
            });
        }

        // ── 4. agents.role nullable (originally NOT NULL with no default) ─────
        // AgentSeeder does not supply 'role' — the field is in Agent::$fillable
        // but no current code reads it (superseded by category/level/title/orb_type).
        // Made nullable to unblock the seeder. Guarded so re-running is a no-op.
        $col = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = 'agents'
               AND column_name  = 'role'"
        );
        if ($col && $col->IS_NULLABLE === 'NO') {
            DB::statement("ALTER TABLE agents MODIFY role VARCHAR(255) NULL");
        }
    }

    public function down(): void
    {
        // Reverse column additions in opposite order. Index drops before column
        // drops so MySQL doesn't reject the column drop on FK/index reference.
        Schema::table('websites', function (Blueprint $table) {
            if (Schema::hasIndex('websites', 'websites_custom_domain_index')) {
                $table->dropIndex('websites_custom_domain_index');
            }
            foreach (['domain_verified', 'custom_domain'] as $col) {
                if (Schema::hasColumn('websites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasIndex('tasks', 'tasks_plan_task_id_index')) {
                $table->dropIndex('tasks_plan_task_id_index');
            }
            if (Schema::hasColumn('tasks', 'plan_task_id')) {
                $table->dropColumn('plan_task_id');
            }
        });

        Schema::table('media', function (Blueprint $table) {
            // Auto-named index from $table->boolean(...)->index() in up().
            if (Schema::hasIndex('media', 'media_is_platform_asset_index')) {
                $table->dropIndex('media_is_platform_asset_index');
            }
            foreach (['tags', 'is_platform_asset'] as $col) {
                if (Schema::hasColumn('media', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        // agents.role is intentionally NOT reverted — existing NULL rows would
        // cause an ALTER ... NOT NULL to fail. Document it as a one-way change.
    }
};
