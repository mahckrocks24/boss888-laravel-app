<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SARAH888 Phase 1B — Executive Commitment Store.
 *
 * Traces to: F1-D01 (Critical, ~70 commitments dictated / 0 persisted),
 *            F1-D10 (self-contradiction on ownership), task lifecycle 1/10.
 *
 * WHY A NEW TABLE RATHER THAN REUSING workspace_goals
 * workspace_goals already exists and is deliberately left alone. It models a
 * MEASURABLE OUTCOME TARGET — goal_type 'keyword_rank_target', a target_json of
 * tracked keywords, and GoalLifecycleService polling rank progress. It has no
 * owner, no supersession chain, no cancellation reason and no verification
 * state, because it never needed them.
 *
 * A commitment is a different thing: an executive obligation stated in
 * conversation. "Nora owns catering outreach", "the audiobook is cancelled",
 * "Project Saffron is now Project Cardamom", "push the print quote two weeks".
 * None of those are measurable targets and none can be expressed as a goal row.
 *
 * These are complementary, not competing: a commitment that turns out to be
 * measurable can later be promoted to a workspace_goal and linked via
 * promoted_goal_id. That direction is deliberate — one store owns the executive
 * record, the other owns automated progress measurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sarah_commitments', function (Blueprint $table) {
            $table->id();

            // ── Identity + provenance ──────────────────────────────────────
            $table->unsignedBigInteger('workspace_id');
            $table->string('conversation_id', 120)->nullable();
            // The message that created this commitment. Phase 1A made this id
            // exist and trustworthy; without it a commitment cannot be traced
            // back to the sentence that produced it, and "where did this come
            // from?" is unanswerable.
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->string('execution_id', 64)->nullable();
            $table->string('actor', 80)->nullable();          // who stated it

            // ── The obligation ─────────────────────────────────────────────
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('objective', 255)->nullable();
            $table->string('owner', 120)->nullable();          // who does it
            $table->string('accountable', 120)->nullable();    // who answers for it
            $table->date('deadline')->nullable();
            $table->string('deadline_text', 120)->nullable();  // "by Friday", kept verbatim
            $table->string('priority', 16)->default('medium'); // critical|high|medium|low

            // ── Lifecycle ──────────────────────────────────────────────────
            // proposed|confirmed|active|blocked|at_risk|completed|verified|
            // cancelled|superseded|expired
            $table->string('status', 24)->default('proposed');
            $table->string('cancellation_reason', 255)->nullable();
            $table->string('verification_state', 24)->default('unverified');
            $table->text('verification_evidence')->nullable();

            // ── Relationships ──────────────────────────────────────────────
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->json('dependencies_json')->nullable();
            $table->json('blockers_json')->nullable();
            $table->unsignedBigInteger('related_project_id')->nullable();
            $table->unsignedBigInteger('promoted_goal_id')->nullable();  // → workspace_goals
            $table->unsignedBigInteger('related_task_id')->nullable();   // → tasks

            // ── Governance + confidence ────────────────────────────────────
            // How sure the extractor was. Below the confirmation threshold a
            // commitment stays 'proposed' and must be confirmed rather than
            // silently entering the executive record.
            $table->decimal('confidence', 4, 3)->default(1.000);
            $table->string('extraction_method', 32)->default('explicit'); // explicit|inferred|manual
            $table->boolean('requires_approval')->default(false);
            $table->integer('cost_estimate_credits')->nullable();

            $table->timestamps();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Workspace-first on every index: cross-workspace reads are the
            // highest-severity failure in the loop priority order, so the
            // cheapest query path is always the isolated one.
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'deadline']);
            $table->index(['workspace_id', 'owner']);
            $table->index('source_message_id');
            $table->index('superseded_by_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sarah_commitments');
    }
};
