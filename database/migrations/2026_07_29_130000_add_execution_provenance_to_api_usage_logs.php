<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-01 / D-02 — AI execution provenance and exact usage.
 *
 * Every column here is ADDITIVE and nullable. No existing column is altered,
 * renamed or dropped, so the single downstream consumer (the admin /api-usage
 * endpoint, routes/api.php:2417) continues to work unchanged whether or not
 * this migration has run.
 *
 * Nothing is backfilled. The 3,869 existing rows keep their original values and
 * their new columns stay NULL, which is the honest representation: we do not
 * know the actual provider for a row written by code that never recorded it.
 * Correcting history is a separate, separately-approved decision (D-11).
 *
 * WHY THESE COLUMNS
 * The runtime already returns all of this. Laravel discarded it and wrote a
 * hardcoded provider plus a strlen/4 token estimate — producing 67 rows that
 * attribute OpenAI traffic to DeepSeek, and output-token counts up to 801x low.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_usage_logs', function (Blueprint $table) {
            // ── Attribution ──────────────────────────────────────────────
            // What the caller asked for, kept separately so the requested and
            // actual pair can never be conflated again.
            $table->string('requested_provider', 50)->nullable()->after('model');
            $table->string('requested_model', 100)->nullable()->after('requested_provider');

            // What actually executed. The legacy `provider`/`model` columns are
            // defined as these values when the provenance flag is on.
            $table->string('actual_provider', 50)->nullable()->after('requested_model');
            $table->string('actual_model', 100)->nullable()->after('actual_provider');

            // ── Fallback ─────────────────────────────────────────────────
            // Without these a provider outage is indistinguishable from a
            // healthy run: the fallback that concealed it returns HTTP 200.
            $table->boolean('fallback_used')->default(false)->after('actual_model');
            // The runtime truncates its reason to 300 chars; 500 leaves margin.
            $table->string('fallback_reason', 500)->nullable()->after('fallback_used');

            // ── Execution context ────────────────────────────────────────
            $table->string('tier', 20)->nullable()->after('fallback_reason');
            $table->string('runtime_version', 32)->nullable()->after('tier');
            $table->string('correlation_id', 64)->nullable()->after('runtime_version');

            // ── Exact usage, as reported by the provider via the runtime ──
            // Kept alongside the legacy tokens_in/tokens_out rather than
            // replacing them, so existing SUM() queries keep working.
            // reasoning_tokens matters specifically for V4: reasoning is
            // always-on and is billed INSIDE completion_tokens.
            $table->unsignedInteger('prompt_tokens')->nullable()->after('total_tokens');
            $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
            $table->unsignedInteger('reasoning_tokens')->nullable()->after('completion_tokens');
            $table->unsignedInteger('cached_tokens')->nullable()->after('reasoning_tokens');

            // 'runtime' = measured. 'estimated' = derived locally because the
            // runtime returned no usage object. Never silently interchangeable.
            $table->string('usage_source', 16)->nullable()->after('cached_tokens');

            // Which pricing key produced cost_usd, so a cost figure can always
            // be traced to the rate that made it.
            $table->string('pricing_source', 40)->nullable()->after('cost_usd');
        });

        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->index(['actual_provider', 'created_at'], 'api_usage_logs_actual_provider_created_at_index');
            $table->index(['fallback_used', 'created_at'], 'api_usage_logs_fallback_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->dropIndex('api_usage_logs_actual_provider_created_at_index');
            $table->dropIndex('api_usage_logs_fallback_created_at_index');
        });

        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->dropColumn([
                'requested_provider', 'requested_model', 'actual_provider', 'actual_model',
                'fallback_used', 'fallback_reason', 'tier', 'runtime_version', 'correlation_id',
                'prompt_tokens', 'completion_tokens', 'reasoning_tokens', 'cached_tokens',
                'usage_source', 'pricing_source',
            ]);
        });
    }
};
