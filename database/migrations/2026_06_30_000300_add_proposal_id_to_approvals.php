<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-06-30 — detection→execution loop (#2).
 *
 * Strategy proposals (Sarah's daily/weekly/monthly recommendations) were created
 * in `strategy_proposals` (status=pending_approval) but NEVER surfaced in the
 * Command Center approvals queue (which reads the `approvals` table), so the user
 * never saw or approved them → nothing executed. This adds `approvals.proposal_id`
 * so an approvable proposal can be mirrored into the approvals queue and approved
 * through the existing UI; ApprovalController routes proposal-linked approvals to
 * ProactiveStrategyEngine::approveProposal (which creates + runs the tasks).
 *
 * Guarded + nullable — existing task approvals (proposal_id NULL) are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('approvals')) return;
        Schema::table('approvals', function (Blueprint $table) {
            if (!Schema::hasColumn('approvals', 'proposal_id')) {
                $table->unsignedBigInteger('proposal_id')->nullable()->after('task_id');
                $table->index('proposal_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('approvals')) return;
        Schema::table('approvals', function (Blueprint $table) {
            if (Schema::hasColumn('approvals', 'proposal_id')) {
                try { $table->dropIndex('approvals_proposal_id_index'); } catch (\Throwable $e) {}
                $table->dropColumn('proposal_id');
            }
        });
    }
};
