<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2B-2 — Provider Registry.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT
 * --------------------------------
 * `infra_providers` is the platform's declaration of WHICH providers exist and
 * WHAT WE BELIEVE they can do. It holds NO secrets — not a token, not a key, not
 * an account password. Credentials live in `infra_provider_credentials` (2B-3),
 * encrypted, with their own lifecycle.
 *
 * This is deliberately SEPARATE from `infra_provider_connections` (Phase 1A),
 * which models a *configured integration instance*. The registry answers "does
 * the platform support Provider X at all", the connection answers "is Provider X
 * wired up for this workspace". Collapsing them would force a platform-level
 * fact to be duplicated per workspace.
 *
 * THREE DISTINCT CONCEPTS — NOT INTERCHANGEABLE (directive §2B-2)
 *   technically registered  = a row exists here
 *   operationally available = enabled AND healthy for a capability+environment
 *   commercially selectable = production_ready AND lifecycle_state = active
 * A provider can be registered and unusable, or usable and not sellable.
 *
 * PROVIDER NEUTRALITY: no vendor name appears in any column name, default or
 * constraint. `provider_key` is opaque data, never parsed for meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_providers')) {
            Schema::create('infra_providers', function (Blueprint $table) {
                $table->bigIncrements('id');

                // IMMUTABLE once created. Enforced in the model, not just here —
                // the commercial layer and provider_resources both reference this
                // string, so renaming it would orphan historical mappings.
                $table->string('provider_key', 64)->unique('infra_prov_key_uq');

                $table->string('display_name', 120);

                // dns | hosting | registrar | email | certificate | backup |
                // monitoring | composite. A provider offering several capabilities
                // is 'composite'; the authoritative list is in the capabilities table.
                $table->string('provider_type', 32);

                // NEVER exposed through a public API. See the model's $hidden.
                // Storing the adapter class here (rather than only in config) lets
                // the registry describe a provider the config has not yet wired.
                $table->string('adapter_class', 191)->nullable();
                $table->string('adapter_version', 32)->nullable();

                // draft|testing|active|degraded|disabled|deprecated|retired
                $table->string('lifecycle_state', 32)->default('draft');

                // Registered != enabled. A provider must be switched on explicitly.
                $table->boolean('enabled')->default(false);

                // Readiness is asserted per environment and is NOT implied by
                // lifecycle_state. A provider can be active in sandbox and not
                // production-ready at the same time.
                $table->boolean('production_ready')->default(false);
                $table->boolean('sandbox_ready')->default(false);

                // Resolution ordering when several providers offer one capability.
                // Lower wins. Not failover — see ProviderResolutionService.
                $table->unsignedSmallInteger('priority')->default(100);

                $table->json('environments_json')->nullable();  // ['sandbox','production']
                $table->json('regions_json')->nullable();       // opaque region codes
                $table->json('currencies_json')->nullable();    // ISO-4217, for cost ingestion
                $table->json('feature_flags_json')->nullable();
                $table->json('metadata_json')->nullable();      // non-secret only

                $table->text('operational_notes')->nullable();

                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['lifecycle_state', 'enabled'], 'infra_prov_state_idx');
                $table->index('provider_type', 'infra_prov_type_idx');
            });
        }

        if (!Schema::hasTable('infra_provider_capabilities')) {
            Schema::create('infra_provider_capabilities', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('provider_id');

                // One of the eight contracted capabilities. Validated against
                // InfraProviderConnection::capabilities() in the service layer so
                // an unknown capability cannot be declared.
                $table->string('capability', 64);

                // Environment is part of the identity: a provider may support
                // certificates in sandbox but not in production.
                $table->string('environment', 32)->default('sandbox');

                // supported = the adapter implements it.
                // enabled   = we permit its use.
                // Registry presence must NOT imply either (directive §2B-2).
                $table->boolean('supported')->default(true);
                $table->boolean('enabled')->default(false);

                $table->string('implementation_version', 32)->nullable();

                // Denormalised pointer to the latest health verdict, for cheap
                // resolution reads. `infra_provider_health` remains authoritative.
                $table->string('health_state', 32)->default('unknown');

                $table->json('regions_json')->nullable();
                $table->json('constraints_json')->nullable();   // rate limits, max records, etc.
                $table->json('feature_flags_json')->nullable();

                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(
                    ['provider_id', 'capability', 'environment'],
                    'infra_provcap_uq'
                );
                $table->index(['capability', 'environment', 'enabled'], 'infra_provcap_res_idx');

                $table->foreign('provider_id', 'infra_provcap_prov_fk')
                    ->references('id')->on('infra_providers')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_provider_capabilities');
        Schema::dropIfExists('infra_providers');
    }
};
