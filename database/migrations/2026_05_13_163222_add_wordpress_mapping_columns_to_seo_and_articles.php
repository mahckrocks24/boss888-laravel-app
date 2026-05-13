<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_content_index', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_content_index', 'wp_post_id')) {
                $table->unsignedBigInteger('wp_post_id')
                      ->nullable()
                      ->after('workspace_id')
                      ->comment('WordPress post ID for this indexed page');
            }
            if (!Schema::hasColumn('seo_content_index', 'wp_attachment_id')) {
                $table->unsignedBigInteger('wp_attachment_id')
                      ->nullable()
                      ->after('wp_post_id')
                      ->comment('WordPress attachment ID for featured image');
            }
        });

        Schema::table('seo_content_index', function (Blueprint $table) {
            if (!Schema::hasColumn('seo_content_index', 'wp_post_id')) {
                return;
            }
            $indexes = collect(\DB::select(
                "SHOW INDEX FROM seo_content_index WHERE Key_name IN
                 ('idx_sci_wp_post_id','idx_sci_wp_attachment_id')"
            ))->pluck('Key_name')->toArray();
            if (!in_array('idx_sci_wp_post_id', $indexes)) {
                $table->index('wp_post_id', 'idx_sci_wp_post_id');
            }
            if (!in_array('idx_sci_wp_attachment_id', $indexes)) {
                $table->index('wp_attachment_id', 'idx_sci_wp_attachment_id');
            }
        });

        Schema::table('articles', function (Blueprint $table) {
            if (!Schema::hasColumn('articles', 'wp_post_id')) {
                $table->unsignedBigInteger('wp_post_id')
                      ->nullable()
                      ->after('workspace_id')
                      ->comment('WordPress post ID after publish');
            }
            if (!Schema::hasColumn('articles', 'wp_thumbnail_id')) {
                $table->unsignedBigInteger('wp_thumbnail_id')
                      ->nullable()
                      ->after('wp_post_id')
                      ->comment('WordPress attachment ID for featured image');
            }
        });

        Schema::table('articles', function (Blueprint $table) {
            if (!Schema::hasColumn('articles', 'wp_post_id')) {
                return;
            }
            $indexes = collect(\DB::select(
                "SHOW INDEX FROM articles WHERE Key_name IN
                 ('idx_articles_wp_post_id','idx_articles_wp_thumbnail_id')"
            ))->pluck('Key_name')->toArray();
            if (!in_array('idx_articles_wp_post_id', $indexes)) {
                $table->index('wp_post_id', 'idx_articles_wp_post_id');
            }
            if (!in_array('idx_articles_wp_thumbnail_id', $indexes)) {
                $table->index('wp_thumbnail_id', 'idx_articles_wp_thumbnail_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seo_content_index', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_sci_wp_post_id');
            $table->dropIndexIfExists('idx_sci_wp_attachment_id');
            if (Schema::hasColumn('seo_content_index', 'wp_post_id')) {
                $table->dropColumn('wp_post_id');
            }
            if (Schema::hasColumn('seo_content_index', 'wp_attachment_id')) {
                $table->dropColumn('wp_attachment_id');
            }
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_articles_wp_post_id');
            $table->dropIndexIfExists('idx_articles_wp_thumbnail_id');
            if (Schema::hasColumn('articles', 'wp_post_id')) {
                $table->dropColumn('wp_post_id');
            }
            if (Schema::hasColumn('articles', 'wp_thumbnail_id')) {
                $table->dropColumn('wp_thumbnail_id');
            }
        });
    }
};
