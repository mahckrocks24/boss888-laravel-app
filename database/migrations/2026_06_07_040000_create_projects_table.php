<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects — the execution vessel for a strategy.
 *
 *   Strategy Room session  →  proposes a strategy
 *                              ↓ "Ratify as Project"
 *                          PROJECT row (this table)
 *                              ↓ has
 *                          execution_plan (task group) + KPIs + milestones
 *                              ↓ closes with
 *                          disposition (succeeded/failed/etc.) + score
 *
 * source_type tells us how this project came into being. Legacy plans
 * (backfilled) get source_type='direct'. New plans birthed in Strategy
 * Room get source_type='strategy_room' with source_meeting_id set.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id');
            $t->string('name', 255);
            $t->text('goal');                            // why this project exists
            $t->text('description')->nullable();         // longer context
            $t->string('status', 20)->default('proposed');
                                                          // proposed | active | paused | completed | archived | cancelled
            $t->string('source_type', 30)->default('direct');
                                                          // direct | strategy_room | sarah_campaign | content_pack
            $t->unsignedBigInteger('source_meeting_id')->nullable();
            $t->unsignedBigInteger('source_chat_message_id')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->unsignedInteger('budget_credits')->default(0);    // planned envelope
            $t->unsignedInteger('budget_spent')->default(0);      // running total
            $t->timestamp('planned_start_at')->nullable();
            $t->timestamp('planned_end_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->json('metadata_json')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();

            // Query patterns:
            //   workspace_id + status         → "active projects"
            //   workspace_id + source_type    → "from Strategy Room"
            //   workspace_id + owner_user_id  → "my projects"
            //   source_meeting_id             → "project from this meeting"
            $t->index(['workspace_id', 'status']);
            $t->index(['workspace_id', 'source_type']);
            $t->index(['workspace_id', 'owner_user_id']);
            $t->index('source_meeting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};