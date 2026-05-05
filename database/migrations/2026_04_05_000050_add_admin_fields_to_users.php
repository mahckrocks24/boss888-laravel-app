<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PATCH v1.0.1 — Adds is_platform_admin to users.
 *
 * is_admin + status were already added by 2026_04_04_400001.
 * This migration adds ONLY the is_platform_admin column (the renamed/upgraded flag).
 * Running both migrations no longer causes a duplicate-column crash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // is_platform_admin: replaces the legacy is_admin for admin panel access.
            // Separate flag so is_admin can remain for future engine-level admin roles.
            if (! Schema::hasColumn('users', 'is_platform_admin')) {
                $table->boolean('is_platform_admin')->default(false)->after('is_admin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_platform_admin')) {
                $table->dropColumn('is_platform_admin');
            }
        });
    }
};
