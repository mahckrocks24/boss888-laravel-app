<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 3B — the canonical infrastructure asset graph + intelligence.
 *
 * ONE GRAPH, NO PARALLEL HIERARCHY (directive: "One canonical infrastructure
 * graph. No duplicated ownership models. No parallel asset hierarchies.").
 *
 *   infra_assets              — the canonical node. Every infrastructure thing
 *                               (website, domain, dns_zone, server, ssl_cert,
 *                               deployment, monitoring_check, backup, email,
 *                               provider) is an asset. Rich existing tables
 *                               (hosted_sites, hosting_accounts, monitor_checks)
 *                               ATTACH to their asset via asset_id — they are not
 *                               duplicated. The asset is the spine.
 *   infra_asset_relationships — the directed edges: domain →attached_to→ website,
 *                               website →hosted_on→ server, server →provided_by→
 *                               provider, monitor →monitors→ asset. This is what
 *                               makes "which customers are affected?" answerable.
 *   infra_incidents           — first-class incidents with the full lifecycle
 *                               (detected→acknowledged→investigating→mitigated→
 *                               resolved→closed). Elevates 3A's monitor_incidents;
 *                               future providers reuse this one model.
 *   infra_incident_transitions— append-only lifecycle history: every transition
 *                               timestamped, audited, actor-recorded, ws-isolated.
 *   infra_monitor_daily       — daily rollup of monitor results, so long-term
 *                               trends survive while raw rows are pruned. This is
 *                               the retention/archival foundation for millions of
 *                               events.
 *
 * SCALE: every table is workspace-scoped and indexed for the executive-dashboard
 * access patterns (by workspace + state, by asset + state, by time). The raw
 * time-series (infra_monitor_results, 3A) stays the write path; infra_monitor_daily
 * is the read path for history, so the two scale independently.
 *
 * ADDITIVE + non-destructive: existing tables gain a nullable asset_id only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── canonical asset node ────────────────────────────────────────────
        if (!Schema::hasTable('infra_assets')) {
            Schema::create('infra_assets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('asset_uid')->unique('infra_asset_uid_uq');
                $table->unsignedBigInteger('workspace_id');

                // website|domain|dns_zone|server|ssl_certificate|deployment|
                // monitoring_check|backup|email_service|provider
                $table->string('asset_type', 32);
                $table->string('name', 191);

                // 🔴 The PTAA distinction the directive requires, structural:
                // adopted|provisioned|managed_externally|managed_by_infra888
                $table->string('management_mode', 24)->default('managed_by_infra888');

                // active|degraded|suspended|terminated|pending
                $table->string('lifecycle_state', 24)->default('active');

                // Rollup health: healthy|degraded|down|unknown. `unknown` is the
                // honest default for dimensions no adapter populates yet.
                $table->string('health_state', 16)->default('unknown');
                $table->timestamp('health_checked_at')->nullable();

                // Rule-based operational risk: ok|watch|at_risk. Deterministic
                // indicators only; predictive scoring is the runtime's job.
                $table->string('risk_state', 16)->default('ok');
                $table->json('risk_reasons_json')->nullable();

                // Provider relationship (nullable — not every asset has one).
                $table->unsignedBigInteger('provider_id')->nullable();
                // Opaque external reference (hostname / provider resource id).
                $table->string('external_ref', 191)->nullable();

                // Polymorphic pointer to the rich existing record this asset
                // represents, so the asset does not duplicate it.
                $table->string('source_type', 48)->nullable(); // hosted_site|hosting_account|monitor_check|provider|external
                $table->unsignedBigInteger('source_id')->nullable();

                $table->json('config_json')->nullable();   // ssl_state, dns_state, deployment_state (unknown until adapter)
                $table->json('metadata_json')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'asset_type'], 'infra_asset_ws_type_idx');
                $table->index(['workspace_id', 'health_state'], 'infra_asset_ws_health_idx');
                $table->index(['workspace_id', 'risk_state'], 'infra_asset_ws_risk_idx');
                $table->index(['workspace_id', 'lifecycle_state'], 'infra_asset_ws_life_idx');
                $table->index('provider_id', 'infra_asset_provider_idx');
                $table->index(['source_type', 'source_id'], 'infra_asset_source_idx');
            });
        }

        // ── graph edges ─────────────────────────────────────────────────────
        if (!Schema::hasTable('infra_asset_relationships')) {
            Schema::create('infra_asset_relationships', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('from_asset_id');
                $table->unsignedBigInteger('to_asset_id');

                // attached_to|hosted_on|provided_by|monitors|secured_by|
                // deploys_to|serves|depends_on
                $table->string('relationship_type', 32);
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->unique(['from_asset_id', 'to_asset_id', 'relationship_type'], 'infra_asset_rel_uq');
                $table->index(['workspace_id', 'from_asset_id'], 'infra_asset_rel_from_idx');
                $table->index(['workspace_id', 'to_asset_id'], 'infra_asset_rel_to_idx');
                $table->index('relationship_type', 'infra_asset_rel_type_idx');

                $table->foreign('from_asset_id', 'infra_asset_rel_from_fk')
                    ->references('id')->on('infra_assets')->cascadeOnDelete();
                $table->foreign('to_asset_id', 'infra_asset_rel_to_fk')
                    ->references('id')->on('infra_assets')->cascadeOnDelete();
            });
        }

        // ── first-class incidents ───────────────────────────────────────────
        if (!Schema::hasTable('infra_incidents')) {
            Schema::create('infra_incidents', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('incident_uid')->unique('infra_incident_uid_uq');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('asset_id');

                $table->string('title', 191);
                $table->string('severity', 16)->default('major'); // critical|major|minor|warning

                // detected|acknowledged|investigating|mitigated|resolved|closed
                $table->string('lifecycle_state', 24)->default('detected');

                // Where the incident came from. monitoring|manual|provider.
                $table->string('source', 24)->default('monitoring');
                $table->string('source_ref', 96)->nullable(); // e.g. monitor_check_id

                $table->text('cause')->nullable();
                $table->text('impact_summary')->nullable();

                $table->timestamp('detected_at');
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('mitigated_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();

                $table->unsignedBigInteger('acknowledged_by')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();

                // Computed on resolve: detected→resolved seconds. Feeds MTTR.
                $table->unsignedBigInteger('time_to_resolve_seconds')->nullable();

                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'lifecycle_state'], 'infra_inc_ws_state_idx');
                $table->index(['asset_id', 'lifecycle_state'], 'infra_inc_asset_state_idx');
                $table->index(['workspace_id', 'detected_at'], 'infra_inc_ws_detected_idx');
                $table->index(['workspace_id', 'severity'], 'infra_inc_ws_sev_idx');

                $table->foreign('asset_id', 'infra_inc_asset_fk')
                    ->references('id')->on('infra_assets')->cascadeOnDelete();
            });
        }

        // ── incident transitions (append-only) ──────────────────────────────
        if (!Schema::hasTable('infra_incident_transitions')) {
            Schema::create('infra_incident_transitions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('incident_id');

                $table->string('from_state', 24)->nullable();
                $table->string('to_state', 24);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_type', 24)->default('system'); // user|system|monitoring
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['incident_id', 'created_at'], 'infra_inctrans_inc_idx');
                $table->index(['workspace_id', 'created_at'], 'infra_inctrans_ws_idx');

                $table->foreign('incident_id', 'infra_inctrans_inc_fk')
                    ->references('id')->on('infra_incidents')->cascadeOnDelete();
            });
        }

        // ── daily monitor rollup (retention/archival foundation) ─────────────
        if (!Schema::hasTable('infra_monitor_daily')) {
            Schema::create('infra_monitor_daily', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('monitor_check_id');
                $table->date('day');

                $table->unsignedInteger('checks')->default(0);
                $table->unsignedInteger('up')->default(0);
                $table->unsignedInteger('down')->default(0);
                $table->unsignedInteger('degraded')->default(0);
                $table->decimal('uptime_pct', 7, 4)->default(0);
                $table->unsignedInteger('avg_response_ms')->nullable();
                $table->unsignedInteger('p95_response_ms')->nullable();
                $table->unsignedInteger('max_response_ms')->nullable();
                $table->unsignedInteger('incidents')->default(0);

                $table->timestamps();

                $table->unique(['monitor_check_id', 'day'], 'infra_mondaily_uq');
                $table->index(['workspace_id', 'day'], 'infra_mondaily_ws_idx');
            });
        }

        // ── attach existing rich tables to the canonical spine ──────────────
        foreach (['infra_hosted_sites', 'infra_hosting_accounts', 'infra_monitor_checks'] as $t) {
            if (Schema::hasTable($t) && !Schema::hasColumn($t, 'asset_id')) {
                Schema::table($t, function (Blueprint $table) use ($t) {
                    $table->unsignedBigInteger('asset_id')->nullable()->after('id');
                    $table->index('asset_id', substr('idx_' . $t . '_asset', 0, 60));
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['infra_hosted_sites', 'infra_hosting_accounts', 'infra_monitor_checks'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'asset_id')) {
                Schema::table($t, function (Blueprint $table) use ($t) {
                    $table->dropIndex(substr('idx_' . $t . '_asset', 0, 60));
                    $table->dropColumn('asset_id');
                });
            }
        }

        Schema::dropIfExists('infra_monitor_daily');
        Schema::dropIfExists('infra_incident_transitions');
        Schema::dropIfExists('infra_incidents');
        Schema::dropIfExists('infra_asset_relationships');
        Schema::dropIfExists('infra_assets');
    }
};
