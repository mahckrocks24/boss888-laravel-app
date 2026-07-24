<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1 (2026-06-23/24) — per-website chatbot knowledge base.
 * Adds nullable website_id to chatbot_knowledge_sources + _chunks so each
 * website's chatbot answers from its own KB. NULL = legacy workspace-level
 * (shared) fallback — fully backward-compatible. No FK (website deletion must
 * not cascade-wipe KB; nullable index is sufficient for the retrieval filter).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chatbot_knowledge_sources', function (Blueprint $t) {
            if (! Schema::hasColumn('chatbot_knowledge_sources', 'website_id')) {
                $t->unsignedBigInteger('website_id')->nullable()->after('workspace_id');
                $t->index(['workspace_id', 'website_id'], 'cks_ws_website_idx');
            }
        });
        Schema::table('chatbot_knowledge_chunks', function (Blueprint $t) {
            if (! Schema::hasColumn('chatbot_knowledge_chunks', 'website_id')) {
                $t->unsignedBigInteger('website_id')->nullable()->after('workspace_id');
                $t->index(['workspace_id', 'website_id'], 'ckc_ws_website_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_knowledge_sources', function (Blueprint $t) {
            $t->dropIndex('cks_ws_website_idx');
            $t->dropColumn('website_id');
        });
        Schema::table('chatbot_knowledge_chunks', function (Blueprint $t) {
            $t->dropIndex('ckc_ws_website_idx');
            $t->dropColumn('website_id');
        });
    }
};
