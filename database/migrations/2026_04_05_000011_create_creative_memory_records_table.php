<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_memory_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('engine', 50)->index();          // write, social, marketing, builder, creative, crm
            $table->string('type', 50)->index();            // image, video, article, email, post, page, outreach, ad
            $table->text('prompt')->nullable();             // the original prompt / request
            $table->text('result_summary')->nullable();     // short summary of what was generated
            $table->string('asset_url')->nullable();        // URL if an asset was generated
            $table->boolean('success')->default(true)->index();
            $table->decimal('quality_score', 3, 2)->nullable(); // 0.00–1.00, set when user rates output
            $table->json('context_json')->nullable();       // request context (goal, audience, etc.)
            $table->json('metadata_json')->nullable();      // additional metadata (blueprint_confidence, etc.)
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_memory_records');
    }
};
