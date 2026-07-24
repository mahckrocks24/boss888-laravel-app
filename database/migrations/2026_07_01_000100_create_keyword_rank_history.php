<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-07-01 — create keyword_rank_history (fix #3 from the SEO↔Sarah alignment
 * forensic). WorkspaceStateGatherer already computes rank deltas by reading this
 * table (guarded behind Schema::hasTable), but the table was never created — so
 * the rank_delta_win / rank_loss proactive rules have been permanently dead.
 * Creating it + having seo:sync-gsc-ranks append a row each run revives that
 * intelligence with zero gatherer changes.
 *
 * Columns chosen to match the gatherer's read exactly:
 *   ->where('workspace_id', $wsId)->where('captured_at', BETWEEN -10d..-7d)
 *   ->get(['keyword_id', 'rank'])
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('keyword_rank_history')) {
            return;
        }
        Schema::create('keyword_rank_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('keyword_id');
            $table->integer('rank')->nullable();
            $table->string('rank_url', 2048)->nullable();
            $table->string('source', 20)->default('gsc');
            $table->timestamp('captured_at')->useCurrent();
            $table->timestamps();

            $table->index(['workspace_id', 'captured_at'], 'krh_ws_captured_idx');
            $table->index('keyword_id', 'krh_keyword_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_rank_history');
    }
};
