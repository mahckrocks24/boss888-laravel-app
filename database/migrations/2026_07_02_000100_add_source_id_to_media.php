<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-07-02 — add media.source_id so a generated/exported asset links cleanly
 * back to its origin row (studio_designs id, etc.). Replaces the export-filename
 * dedup hack in StudioService::saveExportToMedia with a real key. Enterprise
 * schema for the video-editing engine. Nullable + indexed — existing rows unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('media')) return;
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source');
                $table->index(['source', 'source_id'], 'media_source_srcid_idx');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('media')) return;
        Schema::table('media', function (Blueprint $table) {
            if (Schema::hasColumn('media', 'source_id')) {
                try { $table->dropIndex('media_source_srcid_idx'); } catch (\Throwable $e) {}
                $table->dropColumn('source_id');
            }
        });
    }
};
