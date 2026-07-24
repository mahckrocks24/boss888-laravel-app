<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-06-24 — shared credit pool foundation (website=workspace architecture).
 * Adds workspaces.billing_workspace_id: the workspace whose credit wallet a
 * given workspace draws from. Backfilled to SELF for all existing rows, so
 * behavior is byte-for-byte unchanged today. Secondary website-workspaces
 * (created by the builder) will point this at the owner's primary workspace,
 * giving one shared wallet across a user's sites. No FK (avoid cascade on the
 * primary; nullable index is enough).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $t) {
            if (! Schema::hasColumn('workspaces', 'billing_workspace_id')) {
                $t->unsignedBigInteger('billing_workspace_id')->nullable()->after('id')->index();
            }
        });
        DB::statement('UPDATE workspaces SET billing_workspace_id = id WHERE billing_workspace_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $t) {
            $t->dropColumn('billing_workspace_id');
        });
    }
};
