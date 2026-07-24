<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 2B-3 — Provider Credentials.
 *
 * 🔴 THE MOST SENSITIVE TABLE IN INFRA888.
 * A row here can control customer infrastructure: DNS for live domains,
 * certificate issuance, hosting accounts. Treat every column accordingly.
 *
 * STORAGE RULES ENCODED HERE
 *   - `secret_encrypted` is written ONLY through Laravel's `encrypted` cast
 *     (AES-256-CBC via APP_KEY). No plaintext column exists, so there is nowhere
 *     for a plaintext value to be stored even by mistake.
 *   - `secret_fingerprint` is a NON-REVERSIBLE SHA-256 of the secret. It exists so
 *     operators can answer "is this the same token I issued?" and "has rotation
 *     actually changed the value?" without ever reading the secret back.
 *   - `secret_hint` holds at most the last 4 characters, for human identification
 *     only. Never enough to reconstruct a credential.
 *   - The model marks all three `$hidden` and overrides toArray(); see the model.
 *
 * ROTATION WITHOUT OVERWRITE (directive §2B-3)
 * `supersedes_id` / `superseded_by_id` form a chain. Rotation NEVER updates the
 * old row's secret — it creates a NEW row and links them. The full history of
 * which credential was live when remains reconstructible, which is exactly what
 * an incident investigation needs.
 *
 * WORKSPACE INDEPENDENCE
 * There is deliberately NO workspace_id. Provider credentials are platform
 * infrastructure. Binding them to a workspace would imply a tenant could hold or
 * reach them; they must be unreachable to every normal workspace user.
 *
 * NOT VENDOR-SHAPED: `capability_scope_json` is an array because the directive is
 * explicit — do not assume every provider uses one token. Cloudflare's three-token
 * split is one instance of a general pattern, not the model itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('infra_provider_credentials')) {
            Schema::create('infra_provider_credentials', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('provider_id');

                // Stable human reference, e.g. 'dns-primary'. Unique per provider
                // + environment so a sandbox and production credential may share
                // a name without colliding.
                $table->string('credential_key', 96);
                $table->string('environment', 32)->default('sandbox');

                $table->string('label', 120)->nullable();
                $table->string('purpose', 191)->nullable();

                // Scope. Empty capability_scope = NOT "all capabilities"; the
                // resolver treats an unscoped credential as unusable (fail closed).
                $table->json('capability_scope_json')->nullable();
                $table->json('region_scope_json')->nullable();

                // The provider-side account/tenant this credential belongs to.
                // Non-secret. Used to detect "this token is for the wrong account"
                // — precisely the D1 ownership problem.
                $table->string('account_identifier', 191)->nullable();

                // ── secret material ──────────────────────────────────────────
                $table->text('secret_encrypted')->nullable();
                $table->string('secret_fingerprint', 64)->nullable();
                $table->string('secret_hint', 16)->nullable();

                // pending_verification|active|expiring|expired|revoked|invalid|superseded
                $table->string('state', 32)->default('pending_verification');

                $table->timestamp('valid_from')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_reason', 191)->nullable();

                // ── rotation chain ───────────────────────────────────────────
                $table->unsignedBigInteger('supersedes_id')->nullable();
                $table->unsignedBigInteger('superseded_by_id')->nullable();

                // ── verification (never contains secret material) ─────────────
                $table->timestamp('last_verified_at')->nullable();
                $table->boolean('last_verification_ok')->nullable();
                $table->json('granted_capabilities_json')->nullable();
                $table->json('missing_capabilities_json')->nullable();
                $table->string('last_verification_code', 64)->nullable();
                $table->text('last_verification_summary')->nullable();

                // ── attribution ──────────────────────────────────────────────
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('activated_by_user_id')->nullable();
                $table->unsignedBigInteger('revoked_by_user_id')->nullable();

                $table->timestamps();

                $table->unique(
                    ['provider_id', 'credential_key', 'environment'],
                    'infra_provcred_key_uq'
                );
                $table->index(['provider_id', 'state'], 'infra_provcred_state_idx');
                $table->index('expires_at', 'infra_provcred_exp_idx');

                $table->foreign('provider_id', 'infra_provcred_prov_fk')
                    ->references('id')->on('infra_providers')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_provider_credentials');
    }
};
