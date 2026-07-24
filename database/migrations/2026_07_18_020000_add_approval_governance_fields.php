<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 1D — separation-of-duties fields on approvals.
 *
 * WHY
 * ---
 * `ApprovalController::approve()` authorised on workspace membership alone: zero
 * role checks, zero self-approval prevention. Any workspace member — including a
 * VIEWER — could approve any pending approval, including one they created.
 *
 * The fix needs to compare requester against approver, but **the requester was
 * never recorded**: `approvals` stores only `decision_by` (the approver), and
 * `tasks` has no actor column at all. Separation of duties was therefore not
 * merely unenforced, it was unrepresentable.
 *
 * Additive and reversible. No backfill is possible — historical rows genuinely
 * do not know who requested them. `requested_by = NULL` therefore means
 * "requester unknown", and the authorization service treats that as
 * indeterminate and FAILS CLOSED for capabilities that forbid self-approval.
 * There are 101 pending approvals at migration time; see the Phase 1D report for
 * the legacy handling decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('approvals')) {
            return;
        }

        Schema::table('approvals', function (Blueprint $table) {
            if (!Schema::hasColumn('approvals', 'requested_by')) {
                // The human (or agent-on-behalf-of-human) who asked for the action.
                $table->unsignedBigInteger('requested_by')->nullable()->after('workspace_id');
                $table->index('requested_by', 'approvals_requested_by_idx');
            }

            if (!Schema::hasColumn('approvals', 'requester_actor_type')) {
                // user | agent | system — an agent-initiated request still records
                // the human it acted for in requested_by where one exists.
                $table->string('requester_actor_type', 24)->nullable()->after('requested_by');
            }

            if (!Schema::hasColumn('approvals', 'capability_key')) {
                // Resolves the approval policy at decision time even if the
                // engine/action strings drift.
                $table->string('capability_key', 128)->nullable()->after('action');
            }

            if (!Schema::hasColumn('approvals', 'approval_policy_json')) {
                // Snapshot of the policy in force when the approval was created,
                // so a later policy change cannot retroactively reinterpret a
                // historical decision.
                $table->json('approval_policy_json')->nullable()->after('capability_key');
            }

            if (!Schema::hasColumn('approvals', 'decision_actor_type')) {
                $table->string('decision_actor_type', 24)->nullable()->after('decision_by');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('approvals')) {
            return;
        }

        Schema::table('approvals', function (Blueprint $table) {
            foreach (['requested_by', 'requester_actor_type', 'capability_key',
                      'approval_policy_json', 'decision_actor_type'] as $col) {
                if (Schema::hasColumn('approvals', $col)) {
                    if ($col === 'requested_by') {
                        $table->dropIndex('approvals_requested_by_idx');
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
