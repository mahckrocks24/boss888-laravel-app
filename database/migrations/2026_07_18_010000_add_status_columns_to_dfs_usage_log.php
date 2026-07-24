<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DFS-F2 (2026-07-18) — make dfs_usage_log able to tell success from failure.
 *
 * Before this, request() logged only `estimated_cost`. A failed DataForSEO
 * call returns no cost, so it was written as 0.000000 — byte-identical to a
 * genuinely free call. The result: 171 consecutive rows reading $0.00 while
 * the account was returning 402 Payment Required, and a ~2-day SEO outage
 * that the spend log reported as perfectly healthy.
 *
 * The existing 171 rows were ALL failures (402 Payment Required), so the
 * `ok` default of 0 is historically correct for them — no backfill needed.
 *
 * `cache_hit` records entries served from the DFS-F2 response cache (cost 0),
 * so "paid calls avoided" is measurable rather than inferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dfs_usage_log')) {
            return;
        }

        Schema::table('dfs_usage_log', function (Blueprint $table) {
            if (! Schema::hasColumn('dfs_usage_log', 'http_status')) {
                $table->unsignedSmallInteger('http_status')->nullable()->after('estimated_cost');
            }
            if (! Schema::hasColumn('dfs_usage_log', 'api_code')) {
                $table->unsignedInteger('api_code')->nullable()->after('http_status');
            }
            if (! Schema::hasColumn('dfs_usage_log', 'ok')) {
                $table->boolean('ok')->default(false)->after('api_code');
            }
            if (! Schema::hasColumn('dfs_usage_log', 'cache_hit')) {
                $table->boolean('cache_hit')->default(false)->after('ok');
            }
        });

        // Indexed separately so the "is DFS failing right now?" and "what is
        // the cache hit rate?" queries stay cheap as the table grows.
        Schema::table('dfs_usage_log', function (Blueprint $table) {
            $table->index(['ok', 'created_at'], 'dfs_usage_log_ok_created_idx');
            $table->index(['cache_hit', 'created_at'], 'dfs_usage_log_cache_created_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dfs_usage_log')) {
            return;
        }

        Schema::table('dfs_usage_log', function (Blueprint $table) {
            $table->dropIndex('dfs_usage_log_ok_created_idx');
            $table->dropIndex('dfs_usage_log_cache_created_idx');
        });

        Schema::table('dfs_usage_log', function (Blueprint $table) {
            $table->dropColumn(['http_status', 'api_code', 'ok', 'cache_hit']);
        });
    }
};
