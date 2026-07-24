<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * b20 (2026-07-24) — tie every push registration to the session that created it.
 *
 * Without this link, logout could only ever act at USER level: we could not tell
 * which device_tokens row belonged to the phone signing out, so signing out on
 * one device either did nothing or would have killed push on devices the user
 * was still signed in on. That is the gap that let a signed-out phone keep
 * receiving notifications.
 *
 * Nullable on purpose: access tokens minted before this migration carry no `sid`
 * claim, so registrations from those clients land with session_id = NULL and are
 * governed by the coarser user-level check until the app refreshes. They are
 * swept by sarah:prune-device-tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id')->nullable()->after('workspace_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
            $table->dropColumn('session_id');
        });
    }
};
