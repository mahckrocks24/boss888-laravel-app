<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PATCH 8 — Architecture Lock Tier 1.
 *
 * Adds page-snapshot columns to canvas_states without disturbing the
 * existing ManualEditService usage (which keys on asset_id + state_json).
 *
 * BuilderSnapshotService writes:
 *   page_id, sections_json, reason
 *
 * ManualEditService writes (unchanged):
 *   asset_id, state_json, operations_json, status, export_url, export_format
 *
 * Both domains share the same table without column collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canvas_states', function (Blueprint $table) {
            if (! Schema::hasColumn('canvas_states', 'page_id')) {
                $table->unsignedBigInteger('page_id')->nullable()->after('workspace_id');
            }
            if (! Schema::hasColumn('canvas_states', 'sections_json')) {
                $table->longText('sections_json')->nullable()->after('asset_id');
            }
            if (! Schema::hasColumn('canvas_states', 'reason')) {
                $table->string('reason', 100)->nullable()->after('status');
            }
        });

        // Add index for the BuilderSnapshotService::history hot query.
        try {
            Schema::table('canvas_states', function (Blueprint $table) {
                $table->index(['page_id', 'created_at'], 'idx_page_created');
            });
        } catch (\Throwable $e) {
            // Index already exists — ignore.
        }
    }

    public function down(): void
    {
        Schema::table('canvas_states', function (Blueprint $table) {
            try { $table->dropIndex('idx_page_created'); } catch (\Throwable $e) {}
            if (Schema::hasColumn('canvas_states', 'page_id'))       $table->dropColumn('page_id');
            if (Schema::hasColumn('canvas_states', 'sections_json')) $table->dropColumn('sections_json');
            if (Schema::hasColumn('canvas_states', 'reason'))        $table->dropColumn('reason');
        });
    }
};
