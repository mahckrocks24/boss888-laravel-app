<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('seo_images')) { return; }
        Schema::create('seo_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('page_url', 500);
            $table->string('image_url', 500);
            $table->string('alt_text', 500)->nullable();
            $table->string('title_text', 500)->nullable();
            $table->boolean('missing_alt')->default(false);
            $table->boolean('empty_alt')->default(false);
            $table->string('suggested_alt', 500)->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'page_url']);
            $table->unique(['workspace_id', 'page_url', 'image_url'], 'unique_page_image');
        });
    }
    public function down(): void { Schema::dropIfExists('seo_images'); }
};
