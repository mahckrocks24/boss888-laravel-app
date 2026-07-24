<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT VOICE (2026-07-19) — marker for "this completion has been reported
 * to the owner by its assigned agent".
 *
 * Deliberately NOT reusing `sarah_read_at`: that column belongs to
 * SarahReadBackService (Sarah reading results to enrich her own replies).
 * Sharing it would mean whichever loop ran first consumed the task and the
 * other silently skipped it.
 *
 * Backfilled to NOW() for existing rows on purpose — without it, the first
 * run would dump ~1,270 historical completions into the chat threads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks') || Schema::hasColumn('tasks', 'agent_reported_at')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('agent_reported_at')->nullable()->after('sarah_read_at');
            $table->index(['workspace_id', 'status', 'agent_reported_at'], 'tasks_ws_status_reported_idx');
        });

        // Suppress the historical backlog — only work completed AFTER this
        // ships gets announced.
        \Illuminate\Support\Facades\DB::table('tasks')
            ->whereNull('agent_reported_at')
            ->update(['agent_reported_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'agent_reported_at')) {
            return;
        }
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_ws_status_reported_idx');
            $table->dropColumn('agent_reported_at');
        });
    }
};
