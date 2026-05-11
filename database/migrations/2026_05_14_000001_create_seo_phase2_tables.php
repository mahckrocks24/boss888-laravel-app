<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // AI report cache — avoid recomputing on every view
        if (!Schema::hasTable('seo_ai_reports')) {
            Schema::create('seo_ai_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('audit_id')->nullable();
                $table->string('report_type', 50)->default('general');
                $table->string('context_key', 255)->nullable();
                $table->longText('report_json');
                $table->unsignedInteger('tokens_used')->default(0);
                $table->timestamps();
                $table->index(['workspace_id', 'audit_id']);
                $table->index(['workspace_id', 'report_type', 'context_key']);
            });
        }

        // Anchor analysis cache per target page
        if (!Schema::hasTable('seo_anchor_analysis')) {
            Schema::create('seo_anchor_analysis', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('target_url', 500);
                $table->unsignedInteger('total_inbound')->default(0);
                $table->unsignedInteger('unique_anchors')->default(0);
                $table->unsignedInteger('generic_anchors')->default(0);
                $table->json('anchor_distribution')->nullable();
                $table->json('recommendations')->nullable();
                $table->string('health', 20)->default('unknown');
                $table->timestamps();
                $table->index(['workspace_id', 'target_url']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ai_reports');
        Schema::dropIfExists('seo_anchor_analysis');
    }
};
