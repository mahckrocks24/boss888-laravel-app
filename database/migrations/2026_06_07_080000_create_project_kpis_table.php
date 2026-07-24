<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_kpis', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('workspace_id');
            $t->string('name', 255);
            $t->text('description')->nullable();
            // DECIMAL(15,4) covers integers, percentages, currency, and ranks
            $t->decimal('target_value', 15, 4);
            $t->decimal('current_value', 15, 4)->default(0);
            $t->string('unit', 40)->nullable();
                // 'visitors/mo' | 'leads' | 'usd' | 'percent' | 'rank' | etc.
            $t->string('direction', 20)->default('higher_is_better');
                // higher_is_better | lower_is_better
            $t->json('measurement_source_json')->nullable();
                // {type:'manual'} | {type:'crm', query:{...}} | {type:'seo', metric:'...'}
                // | {type:'sarah_estimate', confidence:0.7}
            $t->timestamp('last_measured_at')->nullable();
            $t->unsignedInteger('order_index')->default(0);
            $t->json('metadata_json')->nullable();
                // {rationale:'why Sarah picked this target', baseline:N, ...}
            $t->timestamps();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->index(['workspace_id', 'project_id']);
            $t->index(['project_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_kpis');
    }
};