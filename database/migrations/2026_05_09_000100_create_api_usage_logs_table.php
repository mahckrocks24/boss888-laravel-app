<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PATCH (Intel Fix 4) — api_usage_logs table.
 *
 * RuntimeClient::chatJson + writeDraft + imageGenerate all call
 * logApiUsage() to record per-call provider cost telemetry. The insert
 * was wrapped in try/catch and silently dropped because the table didn't
 * exist on staging — every LLM call's cost was lost.
 *
 * Schema matches what RuntimeClient::logApiUsage() actually writes:
 * provider, model, endpoint, tokens_in, tokens_out, total_tokens, cost,
 * duration_ms, status, workspace_id, error.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->string('provider', 50);                         // deepseek / openai / dall-e-3
            $table->string('model', 100)->nullable();               // deepseek-chat / dall-e-3 / etc.
            $table->string('endpoint', 100)->nullable();            // /ai/run:chat_json / /internal/image/generate / etc.
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 20)->default('success');       // success / error
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_logs');
    }
};
