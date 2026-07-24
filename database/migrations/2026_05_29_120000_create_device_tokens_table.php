<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.4.4 — device_tokens table.
 *
 * One row per (user, device) pair. The expo_push_token is the unique
 * key — Expo issues a token per (Firebase project, device install),
 * so if a token already exists for a different user, we silently
 * re-assign it (covers the "user logs out + a different user logs in
 * on the same phone" case).
 *
 * PushDispatcherService reads from this table to find the targets when
 * an agent reply lands. Token rotation is handled by the mobile client
 * re-registering on every app launch; this migration just owns the
 * persistence side.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $t->foreignId('workspace_id')->nullable()->constrained('workspaces')->onDelete('cascade');
            $t->string('expo_push_token', 255)->unique();
            $t->enum('platform', ['ios', 'android', 'web']);
            $t->string('device_label', 120)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
