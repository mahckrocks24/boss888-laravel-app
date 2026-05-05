<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_content_index', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->text('url');
            $table->string('url_hash', 64)->comment('SHA-256 of url for unique index');
            $table->string('title', 512)->nullable();
            $table->string('meta_title', 512)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('h1', 512)->nullable();
            $table->unsignedSmallInteger('h2_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedSmallInteger('image_count')->default(0);
            $table->unsignedSmallInteger('internal_link_count')->default(0);
            $table->unsignedSmallInteger('external_link_count')->default(0);
            $table->text('canonical')->nullable();
            $table->string('robots', 100)->nullable();
            $table->string('intent', 30)->nullable();  // informational/transactional/commercial/navigational
            $table->string('lang', 10)->nullable();
            $table->boolean('has_schema')->default(false);
            $table->boolean('has_og')->default(false);
            $table->unsignedSmallInteger('content_score')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedInteger('page_size_bytes')->nullable();
            $table->json('score_breakdown_json')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'url_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_content_index');
    }
};
