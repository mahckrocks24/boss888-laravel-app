<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('workspace_knowledge')) return;
        Schema::create('workspace_knowledge', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->string('source_agent', 50);
            $t->string('knowledge_type', 100);
            $t->string('title', 500);
            $t->json('data');
            $t->decimal('relevance_score', 4, 3)->default(1.000);
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();

            $t->index(['workspace_id', 'knowledge_type']);
            $t->index(['workspace_id', 'source_agent']);
            $t->index(['workspace_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_knowledge');
    }
};
