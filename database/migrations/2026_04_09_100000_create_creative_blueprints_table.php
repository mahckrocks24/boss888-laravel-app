<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_blueprints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('type', 50)->default('image'); // image, video, page, email, social, ad
            $table->json('blueprint_json');
            $table->string('layout', 50)->nullable()->index();
            $table->string('subject_type', 50)->nullable()->index();
            $table->string('style', 50)->nullable();
            $table->decimal('score', 5, 2)->default(0);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('source_type', 50)->default('generated');
            $table->decimal('external_score', 5, 2)->default(0);
            $table->unsignedInteger('external_count')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'type']);
            $table->index(['source_type']);
            $table->index(['score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_blueprints');
    }
};
