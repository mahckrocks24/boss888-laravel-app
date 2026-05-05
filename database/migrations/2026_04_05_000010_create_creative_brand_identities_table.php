<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_brand_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('primary_color', 20)->nullable();
            $table->string('secondary_color', 20)->nullable();
            $table->string('accent_color', 20)->nullable();
            $table->json('colors_json')->nullable();       // full color palette array
            $table->json('fonts_json')->nullable();        // font names array
            $table->string('logo_url')->nullable();
            $table->string('voice', 100)->default('professional');   // brand voice descriptor
            $table->string('tone', 100)->default('professional');    // emotional tone
            $table->string('visual_style', 200)->nullable();         // e.g. "clean minimalist, warm photography"
            $table->text('style_notes')->nullable();                  // freeform brand notes
            $table->string('industry', 100)->nullable();
            $table->string('target_audience', 200)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'deleted_at'], 'brand_identity_workspace_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_brand_identities');
    }
};
