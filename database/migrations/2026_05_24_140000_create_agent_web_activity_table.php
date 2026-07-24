<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-24 — AgentBrowser transparency layer.
 *
 * Single source of truth for every external-web touch by any agent.
 * Sarah's morning brief, Bella's god-mode answers, Aria chat replies,
 * Arthur's builder research, James's competitor scan — all of it
 * funnels through one row per call so the user (and we) can see
 * exactly what was fetched, when, by whom, and what came back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_web_activity', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->string('agent_slug', 64)->index();              // dmm, james, priya, arthur, bella, aria, assistant, ...
            $t->unsignedBigInteger('user_id')->nullable()->index(); // null for autonomous (cron-driven)
            $t->enum('action', ['fetch', 'search'])->index();
            $t->text('url_or_query');                            // the URL fetched or the search query
            $t->string('status', 32)->default('pending')->index(); // pending / ok / error / capped / blocked
            $t->string('title', 512)->nullable();
            $t->text('response_preview')->nullable();            // first ~500 chars of body/result snippet
            $t->integer('content_length')->nullable();
            $t->string('content_hash', 64)->nullable();          // sha256 for dedup detection
            $t->integer('duration_ms')->nullable();
            $t->integer('cost_credits')->default(0);
            $t->string('reservation_ref', 64)->nullable();       // credit reservation reference
            $t->text('error')->nullable();
            $t->json('response_data_json')->nullable();          // full structured response (truncated/purged at 90d)
            $t->timestamp('fetched_at')->nullable();
            $t->timestamps();

            $t->index(['workspace_id', 'created_at']);
            $t->index(['workspace_id', 'agent_slug', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_web_activity');
    }
};
