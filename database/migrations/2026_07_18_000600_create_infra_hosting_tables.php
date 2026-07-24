<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 1B foundation — hosting product records.
 *
 * infra_hosting_accounts = the customer's hosting SERVICE ENTITLEMENT.
 * infra_hosted_sites     = an individual site running under that entitlement.
 *
 * Directive §16: "The customer owns a service entitlement, not a VPS object.
 * Do not tie the data model directly to one droplet, one server or one existing
 * nginx installation."
 *
 * Accordingly there is NO server_id, NO droplet_id, NO ip_address column. Where a
 * hosting account physically lives is a PROVIDER RESOURCE (infra_provider_resources),
 * resolvable and re-assignable, never a hardcoded attribute of the entitlement.
 * `region` and `environment` are commercial/product attributes, not machine identity.
 *
 * infra_hosted_sites.website_id links OPTIONALLY to the existing platform `websites`
 * table so a Builder site can be hosted — but hosting does not require a Builder
 * website, and this table is not owned by the Builder engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_hosting_accounts')) {
            Schema::create('infra_hosting_accounts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('subscription_id')->nullable();

                $table->string('name', 120);
                $table->string('state', 32)->default('requested');
                $table->string('previous_state', 32)->nullable();
                $table->string('region', 32)->nullable();          // commercial region, not a host
                $table->string('environment', 16)->default('production'); // production|staging

                // Entitlement snapshot — resolved from the pinned plan at provision
                // time so limit checks never require a join through plan versions.
                $table->unsignedInteger('allowed_sites')->nullable();
                $table->unsignedBigInteger('allowed_storage_mb')->nullable();
                $table->unsignedBigInteger('allowed_bandwidth_mb')->nullable();

                // Latest measured usage — denormalized for list rendering. The
                // authoritative history lives in infra_usage_records.
                $table->unsignedBigInteger('current_storage_mb')->default(0);
                $table->unsignedBigInteger('current_bandwidth_mb')->default(0);
                $table->timestamp('usage_synced_at')->nullable();

                $table->string('backup_state', 32)->default('unknown');   // unknown|healthy|stale|failed
                $table->timestamp('last_backup_at')->nullable();
                $table->string('health_state', 32)->default('unknown');   // unknown|healthy|degraded|down
                $table->timestamp('health_checked_at')->nullable();

                $table->timestamp('provisioned_at')->nullable();
                $table->timestamp('suspended_at')->nullable();
                $table->timestamp('terminated_at')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'state'], 'infra_hosting_ws_state_idx');
                $table->index('health_state', 'infra_hosting_health_idx');
                $table->index('subscription_id', 'infra_hosting_sub_idx');
            });
        }

        if (!Schema::hasTable('infra_hosted_sites')) {
            Schema::create('infra_hosted_sites', function (Blueprint $table) {
                $table->bigIncrements('id');
                // Denormalized workspace_id (not parent-join-only) — deliberate.
                // The 2026-07-15 IDOR sweep proved parent-join-only ownership
                // (pages->websites) is exactly where scoping gets forgotten.
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('hosting_account_id');

                // Optional bridge to the existing Builder website. Nullable on purpose.
                $table->unsignedBigInteger('website_id')->nullable();

                $table->string('name', 191);
                $table->string('primary_hostname', 253)->nullable();
                $table->string('state', 32)->default('requested');
                $table->string('previous_state', 32)->nullable();

                $table->string('ssl_state', 32)->default('none');       // none|pending|active|expiring|failed
                $table->timestamp('ssl_expires_at')->nullable();
                $table->string('deployment_state', 32)->default('none'); // none|deploying|deployed|failed
                $table->timestamp('last_deployed_at')->nullable();

                $table->unsignedBigInteger('storage_mb')->default(0);
                $table->unsignedBigInteger('bandwidth_mb')->default(0);

                $table->json('metadata_json')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'state'], 'infra_sites_ws_state_idx');
                $table->index('hosting_account_id', 'infra_sites_account_idx');
                $table->index('website_id', 'infra_sites_website_idx');
                $table->index('ssl_expires_at', 'infra_sites_ssl_exp_idx');
                $table->unique('primary_hostname', 'infra_sites_hostname_uq');

                $table->foreign('hosting_account_id', 'infra_sites_account_fk')
                      ->references('id')->on('infra_hosting_accounts')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_hosted_sites');
        Schema::dropIfExists('infra_hosting_accounts');
    }
};
