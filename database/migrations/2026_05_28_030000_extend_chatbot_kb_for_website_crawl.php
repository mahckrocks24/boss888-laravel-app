<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Extend source_type enum to include 'website_crawl'.
        // MySQL ENUMs can't be altered idempotently via Schema, so use raw.
        DB::statement("ALTER TABLE chatbot_knowledge_sources MODIFY COLUMN source_type ENUM('file','text','website_crawl') NOT NULL");

        if (! Schema::hasColumn('chatbot_knowledge_sources', 'source_url')) {
            Schema::table('chatbot_knowledge_sources', function (Blueprint $table) {
                $table->string('source_url', 1024)->nullable()->after('label');
            });
        }

        if (! $this->indexExists('chatbot_knowledge_sources', 'idx_cks_ws_type')) {
            Schema::table('chatbot_knowledge_sources', function (Blueprint $table) {
                $table->index(['workspace_id', 'source_type'], 'idx_cks_ws_type');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('chatbot_knowledge_sources', 'idx_cks_ws_type')) {
            Schema::table('chatbot_knowledge_sources', function (Blueprint $table) {
                $table->dropIndex('idx_cks_ws_type');
            });
        }
        if (Schema::hasColumn('chatbot_knowledge_sources', 'source_url')) {
            Schema::table('chatbot_knowledge_sources', function (Blueprint $table) {
                $table->dropColumn('source_url');
            });
        }
        // Revert enum (will fail loudly if any website_crawl rows remain — intentional).
        DB::statement("ALTER TABLE chatbot_knowledge_sources MODIFY COLUMN source_type ENUM('file','text') NOT NULL");
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
        return count($rows) > 0;
    }
};
