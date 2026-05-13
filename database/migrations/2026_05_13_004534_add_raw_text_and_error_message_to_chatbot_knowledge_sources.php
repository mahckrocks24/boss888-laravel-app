<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds two columns that the existing ChatbotKnowledgeService + AdminChatbotController
 * already reference but the original 2026_05_06 migration never created:
 *   - raw_text       : full original source text (for re-indexing without re-upload)
 *   - error_message  : ingestion error surfaced back to the admin UI on status=failed
 *
 * Adding them via guarded ALTER so this migration is idempotent on re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_knowledge_sources', function (Blueprint $table) {
            if (! Schema::hasColumn('chatbot_knowledge_sources', 'raw_text')) {
                $table->longText('raw_text')->nullable()->after('label');
            }
            if (! Schema::hasColumn('chatbot_knowledge_sources', 'error_message')) {
                $table->string('error_message', 500)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_knowledge_sources', function (Blueprint $table) {
            if (Schema::hasColumn('chatbot_knowledge_sources', 'raw_text')) {
                $table->dropColumn('raw_text');
            }
            if (Schema::hasColumn('chatbot_knowledge_sources', 'error_message')) {
                $table->dropColumn('error_message');
            }
        });
    }
};
