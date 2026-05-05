<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_video_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('asset_id')->index();     // parent asset in `assets` table
            $table->string('job_ref', 36)->unique();             // internal UUID
            $table->unsignedTinyInteger('scene_index')->default(1);
            $table->text('scene_prompt');                        // the specific scene prompt dispatched
            $table->string('provider', 30)->nullable();          // minimax | runway | mock
            $table->string('provider_job_id', 100)->nullable();  // job ID returned by provider
            $table->string('status', 20)->default('dispatching')->index(); // dispatching|in_progress|completed|failed|timed_out
            $table->unsignedSmallInteger('poll_attempts')->default(0);
            $table->string('video_url')->nullable();             // final video URL (set when completed)
            $table->text('error')->nullable();
            $table->json('metadata_json')->nullable();           // duration, camera, aspect_ratio, etc.
            $table->timestamps();

            $table->index(['asset_id', 'scene_index']);
            $table->index(['status', 'poll_attempts']);          // for worker queue queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_video_jobs');
    }
};
