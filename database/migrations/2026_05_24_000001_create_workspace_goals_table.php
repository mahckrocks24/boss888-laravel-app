<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-24 FIX 46 — Sarah-as-DMM goal tracking.
 *
 * Persists user-stated goals so Sarah can:
 *   1) Track daily progress on each goal
 *   2) Classify on-track / at-risk / off-track
 *   3) Proactively propose pivots when goal at risk
 *   4) Reference goals in morning brief
 *
 * Goal types (seed list — extensible via 'goal_type' column):
 *   - keyword_rank_target    — "rank in top 10 for X keywords"
 *   - traffic_growth         — "double organic traffic in 90 days"
 *   - lead_volume            — "generate 50 leads/month"
 *   - email_subscribers      — "grow list to 5000"
 *   - social_followers       — "10K LinkedIn followers"
 *   - revenue_attribution    — "$50K/mo from organic"
 *
 * One workspace can have multiple active goals. Sarah surfaces them
 * daily in the morning brief.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('goal_type', 64)->index();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->json('target_json')->nullable()->comment('Goal target spec — schema varies by goal_type. e.g. {"keywords":["X","Y"],"target_rank":10}');
            $table->json('current_state_json')->nullable()->comment('Latest measured state, snapshot');
            $table->date('target_deadline')->nullable();
            $table->date('started_at')->nullable();
            $table->string('status', 32)->default('active')->index()->comment('active | achieved | abandoned | at_risk | off_track');
            $table->integer('priority')->default(50);
            $table->timestamp('last_progress_check_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_goals');
    }
};
