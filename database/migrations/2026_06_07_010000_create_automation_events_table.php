<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * automation_events — AI/agent automation timeline.
 *
 * Distinct from calendar_events (which holds the USER's personal schedule —
 * meetings, customer bookings, CRM activities they handle personally).
 * This table holds everything the system / Sarah does autonomously:
 *   - Plan tasks scheduled by PlanSchedulerService
 *   - Scheduled social posts (when system-routed, not when user handles)
 *   - Scheduled email campaigns (Sarah-driven)
 *   - Sarah's cron triggers (morning brief, weekly review, monthly strategy)
 *   - Drip sequence step firings
 *   - General automation runs
 *
 * Schema mirrors calendar_events. Indexes tuned for the Main Automation
 * Calendar query pattern (per-workspace, per-engine, date range).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('automation_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id');
            $t->string('title', 255);
            $t->text('description')->nullable();
            // Categories for automation events: plan_task, scheduled_post,
            // scheduled_email, scheduled_article, drip_step, sarah_cron,
            // automation_run, strategy_meeting, etc.
            $t->string('category', 40)->default('general');
            // Which engine owns this event (write, social, marketing, sarah, content, etc.)
            $t->string('engine', 30)->nullable();
            // What it references in its native entity table (e.g. plan_tasks.id, social_posts.id)
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('reference_type', 50)->nullable();
            // Display color for calendar UI
            $t->string('color', 10)->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->boolean('all_day')->default(false);
            // Recurrence (for Sarah's cron-driven events: daily / weekly / monthly)
            $t->string('recurrence', 30)->nullable();
            $t->json('recurrence_config_json')->nullable();
            // Optional status tracking — automation_events differ from calendar_events
            // in that the underlying task can be running/completed/failed, and the UI
            // wants to show that on the calendar tile. Defaults to scheduled.
            $t->string('status', 20)->default('scheduled');
            $t->timestamps();

            // Workspace scoping FK — same pattern as calendar_events
            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();

            // Query patterns (Main Automation Cal):
            //   workspace_id + starts_at range → main day/week view
            //   workspace_id + engine + starts_at range → per-engine filter
            //   workspace_id + status → "what's pending / running today"
            //   reference_type + reference_id → "find by entity"
            $t->index(['workspace_id', 'starts_at']);
            $t->index(['workspace_id', 'engine', 'starts_at']);
            $t->index(['workspace_id', 'status']);
            $t->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_events');
    }
};