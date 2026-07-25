<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STUDIO888 MRC-2A Phase I — canonical Studio domain record.
 *
 * creative_jobs is OBSERVATIONAL: every Studio generation/edit/video execution
 * is recorded as a first-class object. It is NOT an execution authority — no
 * execution decision depends on it. compiled_prompt / generation_spec / edit_spec
 * are nullable placeholders for later phases (Prompt Compiler / Specs).
 *
 * Additive + nullable only; no FK constraints (observational, references may be
 * absent). assets.creative_job_id links produced assets back to their job.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('creative_jobs')) {
            Schema::create('creative_jobs', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->unsignedBigInteger('task_id')->nullable()->index();
                $t->unsignedBigInteger('asset_id')->nullable()->index();
                $t->unsignedBigInteger('parent_job_id')->nullable()->index();
                $t->string('type', 32)->default('generation');        // generation / edit / video
                $t->string('capability', 64)->nullable();
                $t->string('provider', 64)->nullable();
                $t->string('provider_model', 128)->nullable();
                $t->string('status', 24)->default('pending')->index(); // pending/running/completed/failed/cancelled/queued
                $t->text('original_prompt')->nullable();
                $t->text('compiled_prompt')->nullable();               // Phase J placeholder — stays NULL
                $t->json('generation_spec')->nullable();               // placeholder
                $t->json('edit_spec')->nullable();                     // placeholder
                $t->json('provider_request')->nullable();
                $t->json('provider_response')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('failed_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasColumn('assets', 'creative_job_id')) {
            Schema::table('assets', function (Blueprint $t) {
                $t->unsignedBigInteger('creative_job_id')->nullable()->after('task_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assets', 'creative_job_id')) {
            Schema::table('assets', function (Blueprint $t) {
                $t->dropColumn('creative_job_id');
            });
        }
        Schema::dropIfExists('creative_jobs');
    }
};
