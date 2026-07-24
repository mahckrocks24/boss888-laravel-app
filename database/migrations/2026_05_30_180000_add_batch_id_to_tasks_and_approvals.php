<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.4.4 (2026-05-30) — Batched approvals.
 *
 * When Sarah replies with create_tasks containing N tasks of the same
 * action (e.g. 10 write_article tasks for "write 10 articles"), today
 * the system creates N task rows + N approval rows. The user sees 10
 * Approve buttons. This migration adds a `batch_id` column to both
 * `tasks` and `approvals` so the chat→approval pipeline can:
 *   1. Group create_tasks items by action under one batch_id
 *   2. Create ONE approval row per (workspace_id, batch_id, action)
 *   3. Render ONE Command Center card with "Approve all N / Reject all N"
 *   4. Cascade the approve/reject decision to all sibling tasks in one txn
 *
 * Nullable + indexed so existing rows are unaffected and existing single-task
 * flows keep working identically (batch_id stays null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('batch_id', 40)->nullable()->after('parent_task_id');
            $table->index(['workspace_id', 'batch_id'], 'tasks_ws_batch_idx');
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->string('batch_id', 40)->nullable()->after('task_id');
            $table->index(['workspace_id', 'batch_id'], 'approvals_ws_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropIndex('approvals_ws_batch_idx');
            $table->dropColumn('batch_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_ws_batch_idx');
            $table->dropColumn('batch_id');
        });
    }
};
