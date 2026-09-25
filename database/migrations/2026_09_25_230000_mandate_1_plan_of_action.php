<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MANDATE-1 (DEC-0018 / SPEC-0023, Owner 2026-09-25: "Fix approval gating … only Sarah's execution plan"):
 * a Plan of Action is a first-class governed object. The customer approves it ONCE (through the ordinary
 * approvals queue — the gate is a task `sarah/execute_plan` that requires approval); the tasks it contains
 * are created when the plan is approved, carry the plan's reference, need no approval of their own, and run
 * only while the plan is live (not declined, revoked, superseded or expired, and inside its spend ceiling).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mandates')) {
            Schema::create('mandates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->string('kind', 32)->default('plan');               // plan | revision
                $table->string('source_type', 32)->nullable();             // meeting | sarah | proposal
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('title', 255);
                $table->text('objective')->nullable();
                $table->text('strategy_text')->nullable();
                $table->json('tasks_json');                                // the plan as presented, frozen at proposal
                $table->json('boundaries_json');                           // {spend_ceiling_credits, validity_days, destinations}
                $table->unsignedInteger('credit_estimate')->default(0);
                $table->string('status', 24)->default('proposed')->index(); // proposed|approved|live|completed|declined|revoked|superseded|expired
                $table->unsignedBigInteger('task_id')->nullable()->index(); // the sarah/execute_plan gate task
                $table->unsignedBigInteger('approval_id')->nullable()->index();
                $table->unsignedBigInteger('proposed_by')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('supersedes_id')->nullable()->index();
                $table->json('meta_json')->nullable();                     // {held: [...], decision_note, revoke_reason}
                $table->timestamps();
                $table->index(['source_type', 'source_id']);
            });
        }
        if (! Schema::hasColumn('tasks', 'mandate_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->unsignedBigInteger('mandate_id')->nullable()->after('execution_plan_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'mandate_id')) {
            Schema::table('tasks', function (Blueprint $table) { $table->dropColumn('mandate_id'); });
        }
        Schema::dropIfExists('mandates');
    }
};
