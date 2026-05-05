<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('media')) {
            Schema::create('media', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->nullable()->index();
                $table->string('filename', 255);
                $table->string('path', 2048);
                $table->string('url', 2048);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedInteger('size_bytes')->default(0);
                $table->unsignedSmallInteger('width')->nullable();
                $table->unsignedSmallInteger('height')->nullable();
                $table->string('source', 50)->default('upload'); // upload, dalle, runtime, import
                $table->string('category', 50)->nullable(); // hero, gallery, blog, avatar, logo
                $table->string('prompt', 2048)->nullable(); // AI generation prompt
                $table->string('model', 50)->nullable(); // dall-e-3, stable-diffusion, etc
                $table->json('metadata_json')->nullable();
                $table->timestamps();
                $table->index(['source', 'category']);
            });
        }
    }
    public function down(): void {
        Schema::dropIfExists('media');
    }
};
