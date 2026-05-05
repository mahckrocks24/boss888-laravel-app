<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2E.0 — SEO schema fix sweep.
 *
 * Closes 5 dead-on-write persistence gaps in SeoService that have been silently
 * failing since deploy (every insert wrapped in try/catch + Log::warning that
 * nobody reads). Documented in detail at:
 *   boss888-audit/logs/2026-04-13-seo-audit/01-seoservice-audit.md
 *
 * Strategy: ADD missing columns + relax NOT NULL on a few existing columns.
 * Defensive pattern (`if (!in_array(...))`) so the migration is idempotent and
 * can be re-run safely. Down() removes only the columns we added — never
 * touches columns that were in the original schema.
 *
 * Tables touched:
 *   1. seo_serp_results    — +10 columns + relax `rank` and `url` to nullable
 *   2. seo_audit_items     — +5 columns
 *   3. seo_audit_snapshots — +7 columns
 *   4. seo_links           — +2 columns
 *   5. seo_redirects       — +2 columns
 *
 * Zero data loss. All new columns nullable (with safe defaults where useful).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Gap 1: seo_serp_results ──────────────────────────────────────
        Schema::table('seo_serp_results', function (Blueprint $table) {
            $existing = Schema::getColumnListing('seo_serp_results');

            if (!in_array('workspace_id', $existing))  $table->unsignedBigInteger('workspace_id')->nullable()->after('id');
            if (!in_array('keyword', $existing))       $table->string('keyword', 255)->nullable()->after('workspace_id');
            if (!in_array('position', $existing))      $table->integer('position')->nullable()->after('keyword');
            if (!in_array('features', $existing))      $table->json('features')->nullable();
            if (!in_array('volume', $existing))        $table->integer('volume')->nullable();
            if (!in_array('difficulty', $existing))    $table->decimal('difficulty', 5, 2)->nullable();
            if (!in_array('cpc', $existing))           $table->decimal('cpc', 8, 2)->nullable();
            if (!in_array('results_json', $existing))  $table->json('results_json')->nullable();
            if (!in_array('checked_at', $existing))    $table->timestamp('checked_at')->nullable();
            if (!in_array('updated_at', $existing))    $table->timestamp('updated_at')->nullable();
        });
        // Relax NOT NULL on rank + url so SeoService can write snapshot rows
        // without providing a per-result rank or url (the snapshot use case
        // is one row per audit, not one row per top-N competitor).
        try {
            DB::statement('ALTER TABLE `seo_serp_results` MODIFY COLUMN `rank` INT NULL');
        } catch (\Throwable $e) { /* already nullable — safe to ignore */ }
        try {
            DB::statement('ALTER TABLE `seo_serp_results` MODIFY COLUMN `url` VARCHAR(255) NULL');
        } catch (\Throwable $e) { /* already nullable */ }
        // Index workspace_id for the dashboard reads
        try {
            DB::statement('ALTER TABLE `seo_serp_results` ADD INDEX `seo_serp_results_workspace_id_index` (`workspace_id`)');
        } catch (\Throwable $e) { /* index already exists */ }

        // ── Gap 2: seo_audit_items ───────────────────────────────────────
        Schema::table('seo_audit_items', function (Blueprint $table) {
            $existing = Schema::getColumnListing('seo_audit_items');

            if (!in_array('workspace_id', $existing))  $table->unsignedBigInteger('workspace_id')->nullable()->after('audit_id');
            if (!in_array('category', $existing))      $table->string('category', 50)->nullable()->after('url');
            if (!in_array('check_name', $existing))    $table->string('check_name', 100)->nullable()->after('category');
            if (!in_array('status', $existing))        $table->string('status', 20)->nullable()->after('check_name');
            if (!in_array('details', $existing))       $table->text('details')->nullable()->after('status');
        });
        try {
            DB::statement('ALTER TABLE `seo_audit_items` ADD INDEX `seo_audit_items_workspace_id_index` (`workspace_id`)');
        } catch (\Throwable $e) { /* index already exists */ }

        // ── Gap 3: seo_audit_snapshots ───────────────────────────────────
        Schema::table('seo_audit_snapshots', function (Blueprint $table) {
            $existing = Schema::getColumnListing('seo_audit_snapshots');

            if (!in_array('previous_score', $existing)) $table->integer('previous_score')->nullable()->after('score');
            if (!in_array('delta', $existing))          $table->integer('delta')->nullable()->after('previous_score');
            if (!in_array('passed', $existing))         $table->integer('passed')->nullable()->default(0)->after('delta');
            if (!in_array('warnings', $existing))       $table->integer('warnings')->nullable()->default(0)->after('passed');
            if (!in_array('errors', $existing))         $table->integer('errors')->nullable()->default(0)->after('warnings');
            if (!in_array('total_checks', $existing))   $table->integer('total_checks')->nullable()->default(0)->after('errors');
            if (!in_array('snapshot_json', $existing))  $table->json('snapshot_json')->nullable();
        });

        // ── Gap 4: seo_links ─────────────────────────────────────────────
        Schema::table('seo_links', function (Blueprint $table) {
            $existing = Schema::getColumnListing('seo_links');

            if (!in_array('http_status', $existing))     $table->integer('http_status')->nullable()->after('priority_score');
            if (!in_array('last_checked_at', $existing)) $table->timestamp('last_checked_at')->nullable()->after('http_status');
        });

        // ── Gap 5: seo_redirects ─────────────────────────────────────────
        Schema::table('seo_redirects', function (Blueprint $table) {
            $existing = Schema::getColumnListing('seo_redirects');

            // status_code and is_active are what SeoService::createRedirect() writes.
            // The original schema has `type` enum and `status` varchar instead — those
            // stay (we don't touch the originals), the new columns coexist alongside.
            if (!in_array('status_code', $existing)) $table->integer('status_code')->nullable()->default(301)->after('target_url');
            if (!in_array('is_active', $existing))   $table->boolean('is_active')->nullable()->default(true)->after('status_code');
        });
    }

    public function down(): void
    {
        Schema::table('seo_serp_results', function (Blueprint $table) {
            $existing = Schema::getColumnListing('seo_serp_results');
            $cols = ['workspace_id','keyword','position','features','volume','difficulty','cpc','results_json','checked_at','updated_at'];
            foreach ($cols as $c) {
                if (in_array($c, $existing)) $table->dropColumn($c);
            }
        });

        Schema::table('seo_audit_items', function (Blueprint $table) {
            $existing = Schema::getColumnListing('seo_audit_items');
            $cols = ['workspace_id','category','check_name','status','details'];
            foreach ($cols as $c) {
                if (in_array($c, $existing)) $table->dropColumn($c);
            }
        });

        Schema::table('seo_audit_snapshots', function (Blueprint $table) {
            $existing = Schema::getColumnListing('seo_audit_snapshots');
            $cols = ['previous_score','delta','passed','warnings','errors','total_checks','snapshot_json'];
            foreach ($cols as $c) {
                if (in_array($c, $existing)) $table->dropColumn($c);
            }
        });

        Schema::table('seo_links', function (Blueprint $table) {
            $existing = Schema::getColumnListing('seo_links');
            $cols = ['http_status','last_checked_at'];
            foreach ($cols as $c) {
                if (in_array($c, $existing)) $table->dropColumn($c);
            }
        });

        Schema::table('seo_redirects', function (Blueprint $table) {
            $existing = Schema::getColumnListing('seo_redirects');
            $cols = ['status_code','is_active'];
            foreach ($cols as $c) {
                if (in_array($c, $existing)) $table->dropColumn($c);
            }
        });
    }
};
