<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 1A — provider abstraction storage.
 *
 * infra_provider_connections = a configured provider integration (credentials live
 *                              in config/env, NEVER here — only a credential REF).
 * infra_provider_resources   = the mapping between one of our business records and
 *                              one external object at a provider.
 *
 * Directive §8: no business record may assume a provider is permanent. Hence:
 *   - `provider` is a string column, not an enum baked into the schema.
 *   - `provider_resource_id` is an OPAQUE reference. Never parsed for meaning.
 *   - `normalized_state` is the customer-facing truth; `provider_state` is the raw
 *     vendor string kept only for operators.
 *   - Column names carry no vendor terminology (no `cloudflare_hostname_id`).
 *
 * This directly fixes the Phase 0 defect where CustomDomainService::connect():66
 * captured the Cloudflare DNS record ID and then THREW IT AWAY, forcing disconnect()
 * to re-query the provider by hostname.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_provider_connections')) {
            Schema::create('infra_provider_connections', function (Blueprint $table) {
                $table->bigIncrements('id');
                // Null workspace_id = platform-level shared connection (our own CF
                // account). Non-null = a customer's own provider account (BYO).
                $table->unsignedBigInteger('workspace_id')->nullable();
                $table->string('provider', 64);
                $table->string('capability', 64);   // hosting|custom_hostname|registrar|dns|email|backup|monitoring
                $table->string('label', 120)->nullable();
                $table->string('state', 32)->default('active'); // active|degraded|disabled

                // NEVER a secret. The name of a config key that holds the credential.
                $table->string('credential_ref', 191)->nullable();

                $table->timestamp('last_health_check_at')->nullable();
                $table->string('last_health_state', 32)->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->text('last_error_summary')->nullable();

                $table->json('settings_json')->nullable();  // non-secret config only
                $table->timestamps();

                $table->index(['capability', 'state'], 'infra_provconn_cap_state_idx');
                $table->index(['workspace_id', 'capability'], 'infra_provconn_ws_cap_idx');
            });
        }

        if (!Schema::hasTable('infra_provider_resources')) {
            Schema::create('infra_provider_resources', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('provider_connection_id')->nullable();

                // Polymorphic link to the owning INFRA888 business record.
                $table->string('owner_type', 64);   // hosting_account | hosted_site | domain | mailbox
                $table->unsignedBigInteger('owner_id');

                $table->string('provider', 64);
                $table->string('provider_resource_type', 64);
                $table->string('provider_resource_id', 191)->nullable();

                $table->string('normalized_state', 32)->default('pending');
                $table->string('provider_state', 64)->nullable();

                $table->timestamp('last_synced_at')->nullable();
                $table->unsignedBigInteger('last_operation_id')->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->text('last_error_summary')->nullable();
                $table->string('idempotency_reference', 191)->nullable();

                $table->json('provider_metadata_json')->nullable(); // vendor-specific extras
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'owner_type', 'owner_id'], 'infra_provres_ws_owner_idx');
                $table->index(['provider', 'provider_resource_type'], 'infra_provres_provider_idx');
                $table->index('normalized_state', 'infra_provres_state_idx');
                $table->unique(
                    ['provider', 'provider_resource_type', 'provider_resource_id'],
                    'infra_provres_provider_id_uq'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_provider_resources');
        Schema::dropIfExists('infra_provider_connections');
    }
};
