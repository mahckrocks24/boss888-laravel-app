<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('workspace_id');
            $t->string('title', 255);
            $t->text('description')->nullable();
            $t->timestamp('target_date')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('status', 20)->default('pending');
                // pending | achieved | missed | cancelled
            $t->unsignedInteger('order_index')->default(0);
            $t->json('success_criteria_json')->nullable();
                // {type:'tasks_complete', task_ids:[]} | {type:'kpi_threshold', kpi_id:N, value:M}
                // | {type:'manual', description:'...'}
            $t->json('evidence_json')->nullable();
                // {task_ids:[], metric_values:{}, notes:'...'} captured when achieved
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->index(['workspace_id', 'project_id']);
            $t->index(['project_id', 'order_index']);
            $t->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_milestones');
    }
};