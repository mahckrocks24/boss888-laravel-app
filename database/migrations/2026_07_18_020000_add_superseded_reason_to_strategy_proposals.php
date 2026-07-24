<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO-DRAIN FIX (2026-07-18) — make every supersede explainable.
 *
 * `superseded` was both TERMINAL and UNEXPLAINED: a grep across app/, routes/
 * and resources/js/ returns zero reads of the value, so once set the proposal
 * is invisible and unrecoverable — and nothing recorded WHY. On ws2 that hid
 * 154 killed proposals in 30 days, 80 of them valid SEO work destroyed by a
 * blind 7-day age wipe.
 *
 * Values written by ProactiveStrategyEngine::supersedeStaleProposals():
 *   signal_resolved:<slug>  — the gap this proposal targeted is now closed
 *   aged_out_7d             — non-SEO proposal, original 7-day behaviour
 *
 * Nullable: historical rows keep NULL (we cannot retroactively know why).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('strategy_proposals')) {
            return;
        }
        if (Schema::hasColumn('strategy_proposals', 'superseded_reason')) {
            return;
        }

        Schema::table('strategy_proposals', function (Blueprint $table) {
            $table->string('superseded_reason', 64)->nullable()->after('status');
            $table->index(['workspace_id', 'status', 'superseded_reason'], 'sp_ws_status_reason_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('strategy_proposals') || ! Schema::hasColumn('strategy_proposals', 'superseded_reason')) {
            return;
        }

        Schema::table('strategy_proposals', function (Blueprint $table) {
            $table->dropIndex('sp_ws_status_reason_idx');
            $table->dropColumn('superseded_reason');
        });
    }
};
