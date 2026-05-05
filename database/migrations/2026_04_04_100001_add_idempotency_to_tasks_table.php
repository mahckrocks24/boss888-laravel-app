<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'idempotency_key')) {
                $table->string('idempotency_key', 128)->nullable()->after('credit_cost');
            }
            if (! Schema::hasColumn('tasks', 'execution_hash')) {
                $table->string('execution_hash', 64)->nullable()->after('idempotency_key');
            }
            if (! Schema::hasColumn('tasks', 'execution_started_at')) {
                $table->timestamp('execution_started_at')->nullable()->after('execution_hash');
            }
            if (! Schema::hasColumn('tasks', 'execution_finished_at')) {
                $table->timestamp('execution_finished_at')->nullable()->after('execution_started_at');
            }
            if (! Schema::hasColumn('tasks', 'current_step')) {
                $table->unsignedSmallInteger('current_step')->default(0)->after('execution_finished_at');
            }
            if (! Schema::hasColumn('tasks', 'total_steps')) {
                $table->unsignedSmallInteger('total_steps')->default(1)->after('current_step');
            }
            if (! Schema::hasColumn('tasks', 'progress_message')) {
                $table->string('progress_message', 500)->nullable()->after('total_steps');
            }
        });

        // Add unique index only if it does not already exist
        if (! Schema::hasIndex('tasks', 'tasks_idempotency_key_unique')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->unique('idempotency_key');
            });
        }
        if (! Schema::hasIndex('tasks', 'tasks_execution_hash_index')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->index('execution_hash');
            });
        }

        // Extend status enum — MySQL only, idempotent via MODIFY COLUMN.
        // Wrapping in try/catch makes this safe on SQLite (tests).
        try {
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE tasks MODIFY COLUMN status " .
                "ENUM('pending','awaiting_approval','queued','running','verifying'," .
                     "'completed','failed','cancelled','blocked','degraded') DEFAULT 'pending'"
            );
        } catch (\Throwable) {
            // Non-MySQL driver (SQLite in tests) — skip enum extension.
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique('tasks_idempotency_key_unique');
            $table->dropIndex('tasks_execution_hash_index');
            $table->dropColumn([
                'idempotency_key', 'execution_hash',
                'execution_started_at', 'execution_finished_at',
                'current_step', 'total_steps', 'progress_message',
            ]);
        });

        try {
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE tasks MODIFY COLUMN status " .
                "ENUM('pending','queued','running','failed','completed') DEFAULT 'pending'"
            );
        } catch (\Throwable) {}
    }
};
