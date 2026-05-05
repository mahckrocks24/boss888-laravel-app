<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Execution Plans ──────────────────────────────────
        // Sarah creates these when processing user requests
        Schema::create('execution_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('created_by')->nullable(); // user who initiated
            $table->string('title');
            $table->text('goal');                          // original user request
            $table->string('status', 20)->default('draft'); // draft, approved, executing, completed, failed, cancelled
            $table->json('strategy_json')->nullable();     // Sarah's strategic analysis
            $table->json('agents_required_json')->nullable(); // which agents are needed
            $table->integer('total_tasks')->default(0);
            $table->integer('completed_tasks')->default(0);
            $table->integer('failed_tasks')->default(0);
            $table->json('results_summary_json')->nullable(); // consolidated results
            $table->json('sarah_evaluation_json')->nullable(); // Sarah's post-execution evaluation
            $table->boolean('requires_approval')->default(false);
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->integer('version')->default(1);        // plan versioning
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'status']);
        });

        // ── Plan Tasks ───────────────────────────────────────
        // Individual tasks within an execution plan
        Schema::create('plan_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->integer('step_order');
            $table->string('engine', 30);
            $table->string('action', 50);
            $table->json('params_json')->nullable();
            $table->string('assigned_agent', 30);          // agent slug
            $table->string('status', 20)->default('pending'); // pending, blocked, executing, completed, failed, skipped
            $table->json('depends_on_json')->nullable();   // array of plan_task IDs this depends on
            $table->json('result_json')->nullable();       // execution result
            $table->json('agent_notes_json')->nullable();  // agent's reasoning/notes about execution
            $table->integer('retry_count')->default(0);
            $table->integer('credits_used')->default(0);
            $table->unsignedBigInteger('task_id')->nullable(); // linked to tasks table
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('plan_id')->references('id')->on('execution_plans')->cascadeOnDelete();
            $table->index(['plan_id', 'status']);
        });

        // ── Agent Delegations ────────────────────────────────
        // Tracks Sarah → Agent delegation with supervision
        Schema::create('agent_delegations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('from_agent', 30)->default('sarah'); // who delegated
            $table->string('to_agent', 30);                     // who received
            $table->string('instruction')->nullable();           // what to do
            $table->string('status', 20)->default('assigned');   // assigned, accepted, executing, completed, failed, escalated
            $table->json('result_json')->nullable();
            $table->json('evaluation_json')->nullable();  // Sarah's evaluation of the result
            $table->decimal('quality_score', 5, 2)->nullable(); // 0-1 quality rating
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'to_agent', 'status']);
        });

        // ── Experiments (A/B Testing) ────────────────────────
        Schema::create('experiments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('engine', 30);
            $table->string('hypothesis');                  // "Subject line A will outperform B"
            $table->string('status', 20)->default('draft'); // draft, running, completed, cancelled
            $table->json('variants_json');                 // [{id, name, config, results}]
            $table->json('success_metrics_json');           // [{metric, target, weight}]
            $table->string('winner_variant')->nullable();
            $table->decimal('statistical_significance', 5, 2)->nullable(); // 0-1
            $table->json('conclusion_json')->nullable();   // final analysis
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiments');
        Schema::dropIfExists('agent_delegations');
        Schema::dropIfExists('plan_tasks');
        Schema::dropIfExists('execution_plans');
    }
};
