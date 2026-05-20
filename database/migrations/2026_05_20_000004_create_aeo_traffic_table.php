<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('aeo_traffic', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('website_id')->nullable();
            $t->enum('type', ['crawler', 'referral']);
            // 'GPTBot' / 'ClaudeBot' / 'chatgpt.com' / 'perplexity.ai' / etc.
            $t->string('source', 64);
            $t->string('url', 2048);
            $t->string('user_agent', 500)->nullable();
            $t->string('referer', 500)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['workspace_id', 'type', 'created_at']);
            $t->index(['workspace_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aeo_traffic');
    }
};
