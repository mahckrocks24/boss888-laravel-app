<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * scheduled_actions — P1 of the platform scheduling capability
 * (SCHEDULING-ARCHITECTURE-2026-06-08.md).
 *
 * Sibling table to automation_events: holds the executable task spec for a
 * scheduled/recurring registry action. automation_events stays the calendar
 * row (display); this holds {engine, action, params, when}. Keeping it
 * separate means the 5-calendar readers are untouched (non-breaking).
 *
 * P1 ships the table + a SHADOW-MODE runner that only logs. Nothing fires
 * until P2 (live) — which routes each due row through the runtime governance
 * brain before Laravel's Orchestrator executes it.
 *
 * Additive only. No existing table or column is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scheduled_actions')) {
            return; // idempotent
        }

        Schema::create('scheduled_actions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('created_by')->nullable(); // user who scheduled it

            // The registry action to run (validated against CapabilityMapService)
            $table->string('agent_slug', 64)->nullable();
            $table->string('engine', 64);
            $table->string('action', 96);
            $table->json('params_json')->nullable();

            // When + how often
            $table->dateTime('fire_at');                          // next fire time
            $table->string('recurrence', 32)->nullable();         // null = one-shot
            $table->json('recurrence_config_json')->nullable();

            // Lifecycle. P1 only ever sets 'shadow_logged'.
            // P2+: scheduled -> fired -> completed/failed (recurring loops back to scheduled)
            $table->string('status', 24)->default('scheduled');   // scheduled|shadow_logged|fired|completed|failed|cancelled
            $table->string('source', 24)->default('agent');       // agent|api|manual

            // Links + bookkeeping
            $table->unsignedBigInteger('automation_event_id')->nullable(); // calendar row (P3)
            $table->unsignedBigInteger('task_id')->nullable();             // task created at fire (P2)
            $table->dateTime('last_fired_at')->nullable();
            $table->unsignedInteger('fire_count')->default(0);
            $table->string('notes', 255)->nullable();

            $table->timestamps();

            // The executor's hot query: due rows for a status.
            $table->index(['status', 'fire_at']);
            $table->index(['workspace_id', 'status', 'fire_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_actions');
    }
};
