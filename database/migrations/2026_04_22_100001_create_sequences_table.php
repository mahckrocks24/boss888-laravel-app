<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->string('status', 20)->default('draft'); // draft | active | paused | archived
            $table->string('trigger_type', 40)->default('manual'); // manual | lead_created | tag_added | form_submitted | date_based
            $table->json('trigger_config_json')->nullable();
            $table->json('steps_json')->nullable(); // materialized snapshot for perf
            $table->json('stats_json')->nullable(); // enrolled, completed, converted
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('workspace_id');
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
