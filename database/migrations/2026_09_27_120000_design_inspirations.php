<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VISION-INSPIRE-1 (Owner 2026-09-27: "when a user sends sarah an image inspiration, she should be able to generate prompt
 * based on the design concept and learn and remembers it for future"). One row per image a business owner shared as
 * inspiration: Sarah's design reading of it, the reusable prompt she wrote from it, and how often it has been used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('design_inspirations')) return;
        Schema::create('design_inspirations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('business_id')->nullable()->index();
            $t->unsignedBigInteger('media_id')->nullable();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->string('image_url', 1024);
            $t->string('title', 120);
            $t->json('analysis_json');
            $t->text('prompt');                                   // reusable scene prompt with a {subject} slot
            $t->json('directions_json')->nullable();              // closest design directions (D1..D10)
            $t->string('owner_note', 500)->nullable();            // what the owner said when sharing it
            $t->boolean('pinned')->default(false);                // "always use this look"
            $t->unsignedInteger('uses')->default(0);
            $t->timestamp('last_used_at')->nullable();
            $t->string('status', 16)->default('active');          // active | forgotten
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_inspirations');
    }
};
