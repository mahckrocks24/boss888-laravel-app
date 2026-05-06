<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add missing columns
        Schema::table('approvals', function (Blueprint $table) {
            if (!Schema::hasColumn('approvals', 'engine')) {
                $table->string('engine', 100)->nullable()->after('workspace_id')->index();
            }
            if (!Schema::hasColumn('approvals', 'action')) {
                $table->string('action', 100)->nullable()->after('engine');
            }
            if (!Schema::hasColumn('approvals', 'data_json')) {
                $table->json('data_json')->nullable()->after('action');
            }
        });

        // 2. Make task_id nullable
        if (Schema::hasColumn('approvals', 'task_id')) {
            try {
                Schema::table('approvals', function (Blueprint $table) {
                    $table->dropForeign(['task_id']);
                });
            } catch (\Exception $e) {
                // FK may not exist by that name -- ignore
            }
            Schema::table('approvals', function (Blueprint $table) {
                $table->unsignedBigInteger('task_id')->nullable()->change();
            });
            Schema::table('approvals', function (Blueprint $table) {
                $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
            });
        }

        // 3. Add 'expired' to status enum if missing
        $enumVals = DB::select("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'approvals' AND COLUMN_NAME = 'status'");
        if ($enumVals && strpos($enumVals[0]->COLUMN_TYPE, 'expired') === false) {
            DB::statement("ALTER TABLE approvals MODIFY COLUMN status
                ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn(['engine', 'action', 'data_json']);
        });
    }
};
