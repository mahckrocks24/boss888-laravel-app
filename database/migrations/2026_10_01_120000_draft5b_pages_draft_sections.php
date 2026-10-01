<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DRAFT-5b (RFC-0021 closure, 2026-10-01): a published site served by the renderer (no static export) keeps its draft in
 * pages.draft_sections_json; Publish changes copies it into sections_json. Nullable, so nothing changes until an owner edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pages') && ! Schema::hasColumn('pages', 'draft_sections_json')) {
            Schema::table('pages', function (Blueprint $t) { $t->json('draft_sections_json')->nullable()->after('sections_json'); });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pages') && Schema::hasColumn('pages', 'draft_sections_json')) {
            Schema::table('pages', function (Blueprint $t) { $t->dropColumn('draft_sections_json'); });
        }
    }
};
