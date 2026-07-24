<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2B-4 — Provider Health.
 *
 * WHY HEALTH IS PER-CAPABILITY, NOT PER-PROVIDER
 * ----------------------------------------------
 * This is not hypothetical — it is the exact situation measured on 2026-07-19.
 * The live token returns:
 *
 *      dns_records      -> 200 OK
 *      custom_hostnames -> Authentication error (10000)
 *      zone settings    -> Unauthorized (9109)
 *
 * The provider is simultaneously HEALTHY for dns and UNAUTHORIZED for
 * certificate and custom_hostname. A single provider-level up/down flag cannot
 * express that, and would have to lie in one direction or the other.
 *
 * So health exists at BOTH levels. `capability` NULL = provider-level rollup.
 *
 * "Do not reduce all provider problems to online/offline" (directive §2B-4).
 * unauthorized, quota_limited, rate_limited and maintenance are distinct states
 * because they demand different responses: unauthorized needs a human with
 * credentials, rate_limited needs patience, maintenance needs a schedule.
 *
 * 🔴 COMMERCIAL ISOLATION
 * Nothing in this table may cause a commercial state change. Provider
 * unavailable != customer suspended. Credential expired != subscription
 * cancelled. There is deliberately no FK, trigger or column linking a health row
 * to a subscription, and ProviderHealthService writes to no commercial table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_provider_health')) {
            Schema::create('infra_provider_health', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('provider_id');

                // NULL = provider-level rollup; non-null = one capability.
                $table->string('capability', 64)->nullable();
                $table->string('environment', 32)->default('sandbox');

                // unknown|healthy|degraded|unavailable|unauthorized|
                // quota_limited|rate_limited|maintenance|disabled
                $table->string('health_state', 32)->default('unknown');

                // ── independent dimensions ───────────────────────────────────
                // Tracked separately rather than folded into health_state, so the
                // reason for a verdict survives the verdict.
                $table->boolean('auth_ok')->nullable();
                $table->boolean('api_available')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();

                $table->string('quota_state', 32)->nullable();      // ok|warning|exhausted
                $table->unsignedBigInteger('quota_remaining')->nullable();
                $table->unsignedBigInteger('quota_limit')->nullable();
                $table->timestamp('quota_resets_at')->nullable();

                $table->timestamp('rate_limited_until')->nullable();
                $table->timestamp('maintenance_until')->nullable();

                // Account-level restrictions the provider reports (plan limits,
                // suspended account, region restriction). Non-secret text only.
                $table->string('account_restriction', 191)->nullable();

                // ── failure tracking, for circuit behaviour ──────────────────
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->string('last_error_code', 64)->nullable();
                $table->text('last_error_summary')->nullable();

                // Distinguishes a verdict derived from real traffic (passive) from
                // one produced by a deliberate read-only probe (active).
                $table->timestamp('checked_at')->nullable();
                $table->string('probe_type', 32)->nullable();       // passive|active|manual
                $table->unsignedBigInteger('checked_by_user_id')->nullable();

                // Credential expiry propagates into health: a credential expiring
                // in 3 days is an operational fact, not only a credential fact.
                $table->unsignedBigInteger('credential_id')->nullable();
                $table->timestamp('credential_expires_at')->nullable();

                $table->json('diagnostics_json')->nullable();       // sanitized only
                $table->timestamps();

                // MySQL treats NULLs as distinct in a unique index, so the
                // provider-level rollup row (capability NULL) is guarded in the
                // service layer rather than relied on here.
                $table->unique(
                    ['provider_id', 'capability', 'environment'],
                    'infra_provhealth_uq'
                );
                $table->index(['health_state', 'environment'], 'infra_provhealth_state_idx');

                $table->foreign('provider_id', 'infra_provhealth_prov_fk')
                    ->references('id')->on('infra_providers')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_provider_health');
    }
};
