<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seo_images', function (Blueprint $table) {
            $table->unsignedBigInteger('wp_attachment_id')->nullable()->after('image_url');
            $table->string('optimization_status', 30)->nullable()->after('scan_method');
            $table->string('optimization_provider', 30)->nullable()->after('optimization_status');
            $table->timestamp('last_optimized_at')->nullable()->after('optimization_provider');
            $table->timestamp('last_verified_at')->nullable()->after('last_optimized_at');
            $table->unsignedInteger('verified_size_bytes')->nullable()->after('last_verified_at');
            $table->unsignedInteger('saved_bytes')->nullable()->after('verified_size_bytes');
            $table->string('webp_url', 500)->nullable()->after('saved_bytes');
            $table->boolean('webp_verified')->default(false)->after('webp_url');

            $table->index(['workspace_id', 'optimization_status'], 'idx_seo_images_optim_status');
            $table->index(['workspace_id', 'wp_attachment_id'], 'idx_seo_images_wp_attach');
        });
    }

    public function down(): void
    {
        Schema::table('seo_images', function (Blueprint $table) {
            $table->dropIndex('idx_seo_images_optim_status');
            $table->dropIndex('idx_seo_images_wp_attach');
            $table->dropColumn([
                'wp_attachment_id',
                'optimization_status',
                'optimization_provider',
                'last_optimized_at',
                'last_verified_at',
                'verified_size_bytes',
                'saved_bytes',
                'webp_url',
                'webp_verified',
            ]);
        });
    }
};
