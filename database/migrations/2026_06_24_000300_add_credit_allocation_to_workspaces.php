<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-06-24 — per-workspace credit allocation cap (Agency overspend guard).
 * Optional monthly ceiling on how much of the SHARED pool a given website-
 * workspace may consume, so an agency doesn't overspend on any one client.
 * NULL = no cap = full shared pool (default → no behavior change).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $t) {
            if (! Schema::hasColumn('workspaces', 'credit_allocation')) {
                $t->unsignedInteger('credit_allocation')->nullable()->after('billing_workspace_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $t) {
            $t->dropColumn('credit_allocation');
        });
    }
};
