<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RFC-0023 P4 (2026-10-02): the recall index - one row per rememberable moment (what the owner said, what Sarah answered,
 * journal lines, outcomes and lessons, confirmed facts), tenancy-scoped, dated, with a MySQL full-text index for retrieval
 * and a reserved embedding column for the day an embedding provider is approved (none has budget today).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('memory_chunks')) return;
        Schema::create('memory_chunks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('business_id')->nullable();
            $t->string('source', 16);                 // owner_message|sarah_message|journal|outcome|fact
            $t->string('ref', 48)->unique();          // msg:28146 | journal:14 | ledger:5 | fact:7
            $t->string('said_by', 8)->default('owner');   // owner|sarah|system
            $t->text('text');
            $t->timestamp('said_at')->index();
            $t->json('embedding')->nullable();        // reserved (P4 known limit)
            $t->timestamp('created_at')->nullable();
            $t->index(['workspace_id', 'said_at']);
            $t->index(['workspace_id', 'source']);
        });
        DB::statement('ALTER TABLE memory_chunks ADD FULLTEXT INDEX memory_chunks_text_ft (text)');
    }

    public function down(): void { Schema::dropIfExists('memory_chunks'); }
};
