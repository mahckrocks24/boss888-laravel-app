<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Search Console — per-workspace OAuth connection.
 *
 * One row per workspace. Holds THAT workspace's own Google tokens + the
 * Search Console property it chose to sync. The platform OAuth client_id/
 * secret live in config/connectors.php (env) — NOT here — because they are
 * shared infrastructure (model B). The client_id / client_secret_enc columns
 * exist only so a future per-workspace "BYO credentials" mode (model A) can
 * slot in without a schema change; under model B they stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->unique();
            $table->string('provider', 32)->default('gsc');

            // Reserved for model-A (BYO) — null under platform model B.
            $table->string('client_id')->nullable();
            $table->text('client_secret_enc')->nullable();

            // Per-workspace OAuth tokens (encrypted at rest via Crypt).
            $table->text('access_token_enc')->nullable();
            $table->text('refresh_token_enc')->nullable();
            $table->dateTime('token_expires_at')->nullable();

            // Chosen Search Console property + connection state.
            $table->string('site_url')->nullable();
            $table->boolean('connected')->default(false);
            $table->string('connected_email')->nullable();
            $table->dateTime('last_sync_at')->nullable();

            $table->timestamps();

            $table->index('connected');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_connections');
    }
};
