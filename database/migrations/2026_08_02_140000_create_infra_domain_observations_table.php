<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · S6 — REGISTRAR OBSERVATION LEDGER.
 *
 * S5 proved the estate can diverge from the registrar without anyone noticing:
 * the registrar said 2030-07-29 while the estate said 2029-07-29 and monitoring
 * reported "no findings". The estate knew what it BELIEVED; nothing was reading
 * what actually EXISTS.
 *
 * This table is the observation half. Each row is one read-only look at the
 * registrar, kept forever, never overwritten. Observations are FACTS WITH A
 * TIMESTAMP — they do not correct the estate, they do not trigger repair, and
 * they carry no authority to change desired state. An operator decides.
 *
 * Keeping history rather than a single "latest" column is deliberate: "the
 * nameservers changed last Tuesday" is a security question, and you cannot ask
 * it of a table that only remembers now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infra_domain_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('customer_domain_id')->nullable();
            $table->string('domain', 253);
            $table->string('provider', 64)->default('namecheap');

            // Did we manage to look at all? `false` is itself an observation.
            $table->boolean('reachable')->default(false);
            $table->string('probe_error_code', 64)->nullable();
            $table->text('probe_error_summary')->nullable();
            $table->unsignedInteger('probe_duration_ms')->nullable();

            // ── OBSERVED (what the registrar says) ──────────────────────────
            $table->timestamp('observed_expires_at')->nullable();
            $table->string('observed_status', 64)->nullable();
            $table->boolean('observed_owned')->nullable();
            $table->boolean('observed_managed_by_us')->nullable();
            $table->boolean('observed_auto_renew')->nullable();
            $table->boolean('observed_is_locked')->nullable();
            $table->json('observed_nameservers')->nullable();
            $table->string('observed_dns_provider', 64)->nullable();
            $table->boolean('observed_uses_our_dns')->nullable();
            $table->string('observed_whois_privacy', 32)->nullable();

            // ── DESIRED (what the estate believed at observation time) ──────
            // Snapshotted so a historical row stays interpretable after the
            // estate later changes.
            $table->timestamp('desired_expires_at')->nullable();
            $table->string('desired_status', 64)->nullable();
            $table->boolean('desired_auto_renew')->nullable();
            $table->boolean('desired_is_locked')->nullable();
            $table->json('desired_nameservers')->nullable();

            // ── DRIFT ───────────────────────────────────────────────────────
            $table->boolean('has_drift')->default(false);
            $table->string('highest_severity', 16)->nullable();
            $table->unsignedSmallInteger('drift_count')->default(0);
            $table->json('drift_json')->nullable();

            $table->json('raw_json')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index('customer_domain_id');
            $table->index('domain');
            $table->index('observed_at');
            $table->index(['has_drift', 'highest_severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_domain_observations');
    }
};
