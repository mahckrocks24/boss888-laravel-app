<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_outcomes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('workspace_id');
            $t->string('outcome', 30);
                // succeeded | partially_succeeded | failed | abandoned
            $t->decimal('composite_score', 5, 2)->nullable();
                // 0-100; null if neither milestones nor KPIs existed
            $t->decimal('milestone_score', 5, 2)->nullable();
            $t->decimal('kpi_score', 5, 2)->nullable();
            $t->json('weights_json')->nullable();
                // {milestone: 0.5, kpi: 0.5}
            $t->json('evidence_json')->nullable();
                // {tasks_done: N, posts_published: N, leads_captured: N, notes: '...'}
            $t->text('narrative')->nullable();
                // Sarah's prose summary
            $t->unsignedBigInteger('disposed_by')->nullable();
            $t->timestamp('disposed_at')->nullable();
            $t->json('metadata_json')->nullable();
            $t->timestamps();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->index(['workspace_id', 'project_id']);
            $t->index(['workspace_id', 'outcome']);
            // One terminal outcome per project (re-disposition replaces via app logic)
            $t->unique(['project_id'], 'project_outcomes_project_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_outcomes');
    }
};