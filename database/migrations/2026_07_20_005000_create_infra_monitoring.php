<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 3A — infrastructure monitoring (first real customer capability).
 *
 * WHY MONITORING IS THE FIRST REAL VERTICAL
 * -----------------------------------------
 * Provisioning, DNS, SSL and backups all require a live provider ADAPTER, which
 * is deliberately gated (D1/D2). Monitoring is the one customer capability that
 * needs NO external provider and NO mutation: it is read-only observation of a
 * target we are allowed to observe. So it is the honest first end-to-end proof
 * of INFRA888 against a real production target (PTAA), exercising tenant
 * isolation, health, incidents, audit and the provider abstraction — with zero
 * risk to customer infrastructure.
 *
 * THREE TABLES:
 *   infra_monitor_checks    — one configured check per monitored target (tenant-scoped)
 *   infra_monitor_results   — append-only time series of observations (tenant-scoped)
 *   infra_monitor_incidents — a down period, opened and closed by the service
 *
 * All tenant-scoped via workspace_id so PTAA's monitoring is isolated exactly
 * like every other tenant's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_monitor_checks')) {
            Schema::create('infra_monitor_checks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');
                // Optional link to the hosted site this check observes.
                $table->unsignedBigInteger('hosted_site_id')->nullable();

                $table->string('name', 160);
                $table->string('check_type', 24)->default('http'); // http|https
                $table->string('target_url', 512);
                $table->unsignedSmallInteger('expected_status')->default(200);
                $table->unsignedInteger('interval_seconds')->default(300);
                $table->unsignedInteger('timeout_seconds')->default(15);

                $table->boolean('enabled')->default(true);

                // Denormalised latest observation, for cheap dashboard reads.
                $table->string('last_status', 16)->default('unknown'); // up|down|degraded|unknown
                $table->unsignedInteger('last_response_ms')->nullable();
                $table->unsignedSmallInteger('last_http_code')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->unsignedInteger('consecutive_failures')->default(0);

                // Open-incident anchor: when the target first went down.
                $table->timestamp('down_since')->nullable();

                // Which provider/connector performs the observation.
                $table->string('monitor_provider', 64)->default('selfhosted');

                $table->json('metadata_json')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'enabled'], 'infra_moncheck_ws_idx');
                $table->index('last_status', 'infra_moncheck_status_idx');
            });
        }

        if (!Schema::hasTable('infra_monitor_results')) {
            Schema::create('infra_monitor_results', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('monitor_check_id');

                $table->string('status', 16);                 // up|down|degraded
                $table->unsignedSmallInteger('http_code')->nullable();
                $table->unsignedInteger('response_ms')->nullable();
                $table->string('error_code', 64)->nullable();
                $table->string('error_summary', 255)->nullable();

                // Distinguishes an observation from a real operation vs a probe.
                $table->string('probe_type', 16)->default('scheduled'); // scheduled|manual

                $table->timestamp('checked_at');

                $table->index(['monitor_check_id', 'checked_at'], 'infra_monres_check_idx');
                $table->index(['workspace_id', 'checked_at'], 'infra_monres_ws_idx');

                $table->foreign('monitor_check_id', 'infra_monres_check_fk')
                    ->references('id')->on('infra_monitor_checks')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('infra_monitor_incidents')) {
            Schema::create('infra_monitor_incidents', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('monitor_check_id');

                $table->timestamp('started_at');
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();

                $table->string('cause', 191)->nullable();
                $table->unsignedInteger('failure_count')->default(1);
                $table->string('state', 16)->default('open'); // open|resolved

                $table->timestamps();

                $table->index(['monitor_check_id', 'state'], 'infra_moninc_check_idx');
                $table->index(['workspace_id', 'started_at'], 'infra_moninc_ws_idx');

                $table->foreign('monitor_check_id', 'infra_moninc_check_fk')
                    ->references('id')->on('infra_monitor_checks')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_monitor_incidents');
        Schema::dropIfExists('infra_monitor_results');
        Schema::dropIfExists('infra_monitor_checks');
    }
};
