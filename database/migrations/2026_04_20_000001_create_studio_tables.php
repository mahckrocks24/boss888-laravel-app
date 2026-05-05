<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Studio Engine — AI-powered social media image editor.
 * Two tables: canonical template library + per-workspace saved designs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 120);
            $table->enum('category', [
                'promotional', 'quote', 'product', 'event',
                'educational', 'brand', 'story', 'carousel', 'seasonal',
            ])->index();
            $table->string('sub_category', 60)->nullable();
            $table->json('industry_tags')->nullable();
            $table->string('demographic', 60)->nullable()->index();
            $table->enum('format', ['square', 'portrait', 'story', 'landscape', 'pinterest'])->index();
            $table->unsignedInteger('canvas_width');
            $table->unsignedInteger('canvas_height');
            $table->longText('layers_json');
            $table->string('preview_image_url', 2048)->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();
        });

        Schema::create('studio_designs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('name', 120);
            $table->enum('format', ['square', 'portrait', 'story', 'landscape', 'pinterest']);
            $table->unsignedInteger('canvas_width');
            $table->unsignedInteger('canvas_height');
            $table->longText('layers_json');
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('exported_url', 2048)->nullable();
            $table->enum('status', ['draft', 'exported'])->default('draft')->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_designs');
        Schema::dropIfExists('studio_templates');
    }
};
