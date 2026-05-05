<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — platform-wide media library foundation.
 *
 * Adds six columns to the existing `media` table so it can host images,
 * videos, documents, and audio under a single unified surface with
 * public/private visibility and engine-usage tracking.
 *
 * Idempotent — each column guarded by hasColumn so re-runs are safe.
 * Existing rows are backfilled:
 *   - asset_type inferred from mime_type
 *   - is_public seeded from is_platform_asset
 */
return new class extends Migration {
    public function up(): void
    {
        // Recovery: ensure legacy columns exist (lost from staging snapshot)
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'is_platform_asset')) {
                $table->boolean('is_platform_asset')->default(false)->after('source')->index();
            }
            if (!Schema::hasColumn('media', 'tags')) {
                $table->json('tags')->nullable()->after('category');
            }
        });

        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'asset_type')) {
                $table->enum('asset_type', ['image', 'video', 'document', 'audio'])
                    ->default('image')
                    ->after('mime_type')
                    ->index();
            }
            if (!Schema::hasColumn('media', 'file_url')) {
                $table->string('file_url', 2048)->nullable()->after('url');
            }
            if (!Schema::hasColumn('media', 'thumbnail_url')) {
                $table->string('thumbnail_url', 2048)->nullable()->after('file_url');
            }
            if (!Schema::hasColumn('media', 'duration_seconds')) {
                $table->integer('duration_seconds')->nullable()->after('height');
            }
            if (!Schema::hasColumn('media', 'is_public')) {
                $table->boolean('is_public')->default(false)->after('is_platform_asset')->index();
            }
            if (!Schema::hasColumn('media', 'used_in')) {
                $table->json('used_in')->nullable()->after('tags');
            }
        });

        // Backfill asset_type from existing mime_type — safe to re-run.
        DB::table('media')->where('mime_type', 'like', 'image/%')
            ->update(['asset_type' => 'image']);
        DB::table('media')->where('mime_type', 'like', 'video/%')
            ->update(['asset_type' => 'video']);
        DB::table('media')->where('mime_type', 'like', 'audio/%')
            ->update(['asset_type' => 'audio']);
        DB::table('media')
            ->where(function ($q) {
                $q->where('mime_type', 'like', 'application/pdf')
                  ->orWhere('mime_type', 'like', 'application/%document%')
                  ->orWhere('mime_type', 'like', 'application/msword')
                  ->orWhere('mime_type', 'like', 'text/%');
            })
            ->update(['asset_type' => 'document']);

        // Backfill is_public = 1 for platform-tagged assets so admins see them
        // as cross-workspace-visible from day one.
        DB::table('media')->where('is_platform_asset', 1)->update(['is_public' => true]);
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            foreach (['asset_type', 'file_url', 'thumbnail_url', 'duration_seconds', 'is_public', 'used_in'] as $col) {
                if (Schema::hasColumn('media', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
