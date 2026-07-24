<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-24 FIX 53 — Workspace timezone awareness.
 *
 * Adds a single `timezone` column (IANA name, default 'UTC') so the
 * sarah:morning-brief scheduler can fire at 08:00 local-time per
 * workspace instead of a single global 08:00 UTC.
 *
 * The cron itself becomes hourly; SarahMorningBriefCommand filters
 * workspaces whose current local hour is 8 and that haven't already
 * received today's brief (tracked via workspace_memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('workspaces', 'timezone')) {
            Schema::table('workspaces', function (Blueprint $t) {
                $t->string('timezone', 64)->default('UTC')->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workspaces', 'timezone')) {
            Schema::table('workspaces', function (Blueprint $t) {
                $t->dropColumn('timezone');
            });
        }
    }
};
