<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DFS-F1 (2026-06-21) — DataForSEO cost telemetry.
 *
 * Minimal spend-visibility ledger (NOT a billing system). One row per DFS API
 * round-trip, written from DataForSeoConnector::request(): which workspace
 * (best-effort), which feature, which endpoint, the DFS-returned cost, and when.
 * Lets future audits identify DFS spend immediately instead of reconstructing
 * it from proxy tables.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dfs_usage_log')) {
            return;
        }
        Schema::create('dfs_usage_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->string('feature', 64)->default('unknown')->index();
            $table->string('endpoint', 191)->index();
            $table->decimal('estimated_cost', 12, 6)->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dfs_usage_log');
    }
};
