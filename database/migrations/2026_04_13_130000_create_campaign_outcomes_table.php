<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create campaign_outcomes table — closes the missing-table gap that broke
 * SarahOrchestrator::analyze() at the very first step.
 *
 * GlobalKnowledgeService reads + writes from this table in 5 methods:
 *   - learnFromOutcome()    INSERT
 *   - getTopStrategies()    SELECT (used by SarahOrchestrator::analyze)
 *   - getSeasonalPatterns() SELECT
 *   - getTrends()           SELECT
 *   - getLifecycleInsights() SELECT
 *
 * Phase 0.9 was deferred per planner Q5 because the table needed a real
 * consumer before specifying baseline rows. Sarah is that consumer — she
 * calls getTopStrategies() in step 1 of receive() and crashes when the table
 * doesn't exist. This migration creates it empty; learnFromOutcome() will
 * populate it organically as engine campaigns complete and report outcomes.
 *
 * Schema derived from the actual columns referenced by GlobalKnowledgeService.
 * All nullable except workspace_id/engine/campaign_type which are required by
 * the insert logic in learnFromOutcome().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_outcomes')) return;

        Schema::create('campaign_outcomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('engine', 30);                       // crm | marketing | social | seo | etc
            $table->string('campaign_type', 50);                // email_blast | post_series | seo_audit | etc
            $table->string('industry', 100)->nullable();
            $table->string('region', 50)->nullable();
            $table->json('strategy_json')->nullable();
            $table->json('results_json')->nullable();
            $table->decimal('effectiveness_score', 4, 3)->nullable();  // 0.000 - 1.000
            $table->json('learnings_json')->nullable();
            $table->boolean('contributed_to_global')->default(false);
            $table->timestamps();

            $table->index('workspace_id');
            $table->index(['engine', 'effectiveness_score']);
            $table->index(['industry', 'effectiveness_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_outcomes');
    }
};
