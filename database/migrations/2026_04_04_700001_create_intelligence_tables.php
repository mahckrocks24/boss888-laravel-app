<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Global Knowledge Store ───────────────────────────
        // Anonymized best practices shared across all workspaces
        // NEVER contains company-specific data — only patterns, trends, effectiveness
        Schema::create('global_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50);     // seo, content, social, creative, crm, marketing, industry
            $table->string('subcategory', 50)->nullable(); // keyword_strategy, post_timing, design_trend
            $table->string('industry', 50)->nullable();    // real_estate, interior_design, saas, ecommerce
            $table->string('region', 30)->nullable();      // mena, europe, sea, global
            $table->string('insight_type', 30);  // pattern, trend, benchmark, best_practice, ab_result
            $table->text('insight');              // The actual knowledge
            $table->json('metrics_json')->nullable();  // supporting data: {ctr: 0.12, conversion: 0.03}
            $table->decimal('confidence', 5, 2)->default(0.5); // 0-1, increases with more data points
            $table->integer('data_points')->default(1);     // how many campaigns/actions contributed
            $table->string('source_engine', 30)->nullable(); // which engine generated this
            $table->timestamps();

            $table->index(['category', 'industry']);
            $table->index(['subcategory', 'region']);
        });

        // ── Agent Experience Stats ───────────────────────────
        // Per-agent performance profile — like a human resume that grows
        Schema::create('agent_experience_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->string('metric_key', 50);    // tasks_completed, articles_written, campaigns_managed
            $table->string('industry', 50)->nullable();
            $table->string('period', 20)->default('all_time'); // all_time, monthly, weekly
            $table->bigInteger('value_int')->default(0);
            $table->decimal('value_decimal', 12, 4)->default(0);
            $table->json('breakdown_json')->nullable(); // detailed breakdown
            $table->timestamps();

            // FIX: auto-name = 65 chars (>64 MySQL limit). Explicit short name used.
            $table->unique(['agent_id', 'metric_key', 'industry', 'period'], 'agent_exp_stats_unique');
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
        });

        // ── Engine Intelligence ──────────────────────────────
        // Per-engine knowledge base — what tools exist, how to use them, effectiveness data
        Schema::create('engine_intelligence', function (Blueprint $table) {
            $table->id();
            $table->string('engine', 30);         // crm, seo, write, creative, etc.
            $table->string('knowledge_type', 30);  // tool_blueprint, effectiveness_data, best_practice, constraint
            $table->string('key', 100);            // tool name or concept
            $table->text('content');               // The intelligence content
            $table->json('metadata_json')->nullable();
            $table->integer('usage_count')->default(0); // how often agents use this
            $table->decimal('effectiveness_score', 5, 2)->nullable(); // 0-1
            $table->timestamps();

            $table->index(['engine', 'knowledge_type']);
            $table->unique(['engine', 'knowledge_type', 'key']);
        });

        // ── Campaign Outcomes ────────────────────────────────
        // Anonymized campaign results for A/B testing and learning
        Schema::create('campaign_outcomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('engine', 30);
            $table->string('campaign_type', 50);   // seo_campaign, email_blast, social_series, content_push
            $table->string('industry', 50)->nullable();
            $table->string('region', 30)->nullable();
            $table->json('strategy_json');          // what was the approach
            $table->json('results_json');           // what happened
            $table->decimal('effectiveness_score', 5, 2)->nullable();
            $table->json('learnings_json')->nullable(); // extracted lessons
            $table->boolean('contributed_to_global')->default(false); // has this been anonymized into global_knowledge?
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['engine', 'industry', 'effectiveness_score']);
        });

        // ── Agent Workspace Memory (enhanced) ────────────────
        // Per-agent-per-workspace memories — project-specific
        Schema::create('agent_workspace_memory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('memory_type', 30);    // preference, insight, context, learning, feedback
            $table->string('key', 150);
            $table->text('value');
            $table->decimal('relevance_score', 5, 2)->default(0.5);
            $table->integer('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            $table->unique(['workspace_id', 'agent_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_workspace_memory');
        Schema::dropIfExists('campaign_outcomes');
        Schema::dropIfExists('engine_intelligence');
        Schema::dropIfExists('agent_experience_stats');
        Schema::dropIfExists('global_knowledge');
    }
};
