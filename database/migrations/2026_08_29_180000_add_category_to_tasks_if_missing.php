<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tasks.category drift (memory levelup-tasks-category-migration-drift): the column exists on the live DB
 * (added by hand, never migrated) and is missing on any freshly migrated DB — TaskService::create then fails
 * with "Unknown column 'category'". This migration mirrors the live definition and is a no-op where present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tasks', 'category')) {
            DB::statement("ALTER TABLE `tasks` ADD COLUMN `category` ENUM('research','create','optimize','publish','crm','campaign','operations') NULL DEFAULT NULL AFTER `action`");
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: the live DB carried this column before the migration existed.
    }
};
