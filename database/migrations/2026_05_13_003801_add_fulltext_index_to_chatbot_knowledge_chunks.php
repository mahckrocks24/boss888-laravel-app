<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the missing FULLTEXT index on chatbot_knowledge_chunks.chunk_text.
 *
 * Without this, ChatbotKnowledgeService::retrieveChunks() throws on the
 * MATCH() AGAINST() path every time and falls back to the strict
 * AND-chained LIKE branch — which misses most natural-language queries.
 * Adding the index turns FULLTEXT NATURAL LANGUAGE retrieval on.
 *
 * InnoDB FULLTEXT (MySQL 5.6+) — supported on staging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_knowledge_chunks', function (Blueprint $table) {
            $table->fullText('chunk_text');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_knowledge_chunks', function (Blueprint $table) {
            $table->dropFullText(['chunk_text']);
        });
    }
};
