<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · E1 — BUSINESS EMAIL: DOMAIN (root entity).
 *
 * The connected domain is the root object, mirroring Website-as-root in Hosting.
 * A mailbox, alias, forwarder or catch-all cannot exist without one.
 *
 * WHAT IS DELIBERATELY ABSENT FROM THIS TABLE
 *
 *   No provider name, anywhere. Not in a column, not in a default, not in a
 *   comment. Provider identity reaches this row only through
 *   `provider_connection_id`, an opaque foreign key into the generic provider
 *   registry. Enforced by BusinessEmailWhiteLabelGuardTest, not by convention.
 *
 *   No `provider_domain_ref` column. The platform already has one table whose
 *   entire job is mapping a business record to an opaque external object —
 *   `infra_provider_resources`, whose owner_type comment has listed `mailbox`
 *   as an anticipated owner since Phase 1A. Hosting stores no provider ref on
 *   `infra_hosting_accounts` for exactly this reason. Adding per-entity ref
 *   columns here would build a second, divergent provider spine.
 *
 *   No `verified` boolean. Verification is a six-state evidence machine
 *   (EmailVerificationState) held in `verification_state`. StateMachine.php
 *   records what happened last time this project modelled externally-dependent
 *   provisioning as booleans.
 *
 *   No mailbox counter. A denormalised count drifts silently; the count is
 *   derived from email_mailboxes, which is indexed for it.
 *
 * TENANCY. `workspace_id` carries no database foreign key, matching every other
 * INFRA888 table (infra_operations, infra_provider_resources, infra_usage_records).
 * That is a deliberate consistency choice, not an oversight: adding a
 * restrict-on-delete constraint against `workspaces` would change workspace
 * deletion semantics for every other workstream. Tenancy is enforced by
 * BelongsToWorkspace (global scope + immutable workspace_id), by policy, and by
 * the composite indexes below.
 *
 * NULLABILITY. Every nullable column here is either an evidence timestamp, an
 * actor, or a link to something that genuinely may not exist yet. None of them
 * stands in for a lifecycle: the lifecycle is `lifecycle_state`, which is NOT
 * NULL and always holds a declared state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_domains')) {
            return;
        }

        Schema::create('email_domains', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id');

            // RFC 5321 section 4.5.3.1 — a domain is at most 253 octets.
            $table->string('domain', 253);

            // Links into the Digital Estate. Both nullable because a customer
            // may operate email on a domain we neither registered nor serve a
            // website for, and that is a legitimate configuration rather than a
            // missing value.
            $table->unsignedBigInteger('customer_domain_id')->nullable();
            $table->unsignedBigInteger('website_id')->nullable();

            // Custody governs what we are ALLOWED to know about this domain and
            // at what confidence. Reuses the S8 model verbatim — see
            // App\Engines\Infrastructure\Observation\Custody. 'unknown' is an
            // honest default, not a placeholder: before resolution we genuinely
            // do not know, and assuming the flattering answer is how a
            // monitoring system becomes confidently wrong.
            $table->string('custody', 24)->default('unknown');

            // EmailDomainState. NOT NULL — there is no such thing as a domain
            // with no lifecycle.
            $table->string('lifecycle_state', 32)->default('connected');
            $table->string('previous_state', 32)->nullable();

            // EmailVerificationState. Moves independently of lifecycle_state: a
            // live domain whose DKIM record was deleted this morning is active
            // and drifted at the same time.
            $table->string('verification_state', 32)->default('unverified');

            // Rolled up from observation. 'unknown' until something is observed.
            $table->string('health_state', 24)->default('unknown');

            // The ONLY provider identity on this row. Opaque foreign key into
            // the generic registry; never parsed, never rendered to a customer.
            $table->unsignedBigInteger('provider_connection_id')->nullable();

            // Evidence, not claims. Set only from a recorded observation.
            $table->timestamp('dns_verified_at')->nullable();
            $table->timestamp('last_observed_at')->nullable();
            $table->unsignedBigInteger('last_observation_id')->nullable();

            // Governed-operation linkage into infra_operations. This is how an
            // email action inherits idempotency, retry classification, timeout
            // handling and approval linkage instead of reimplementing them.
            $table->unsignedBigInteger('last_operation_id')->nullable();

            // Actor provenance for every state change.
            $table->timestamp('state_changed_at')->nullable();
            $table->unsignedBigInteger('state_changed_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamp('terminated_at')->nullable();

            // Non-secret, provider-neutral settings only.
            $table->json('settings_json')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // IDENTITY is enforced below, after the table exists — see the
            // ALTER at the end of up(). It cannot be expressed with the schema
            // builder, and the reason is load-bearing.

            $table->index(['workspace_id', 'lifecycle_state'], 'email_dom_ws_state_idx');
            $table->index(['lifecycle_state', 'verification_state'], 'email_dom_state_ver_idx');
            $table->index(['workspace_id', 'domain'], 'email_dom_ws_domain_idx');
            $table->index('provider_connection_id', 'email_dom_provconn_idx');
            $table->index('custody', 'email_dom_custody_idx');
            $table->index('customer_domain_id', 'email_dom_custdom_idx');
            $table->index('last_observed_at', 'email_dom_observed_idx');

            // A provider connection that domains are bound to may not be
            // hard-deleted out from under them. Nulling the binding silently
            // would leave a live domain pointing at nothing.
            $table->foreign('provider_connection_id', 'email_dom_provconn_fk')
                ->references('id')->on('infra_provider_connections')
                ->restrictOnDelete();
        });

        // IDENTITY — "one LIVE mail domain per name, unlimited soft-deleted
        // history".
        //
        // The obvious spelling, UNIQUE(domain, deleted_at), DOES NOT WORK, and
        // silently: MySQL permits duplicate rows in a unique index whenever any
        // indexed column is NULL, and deleted_at is NULL for every live row. The
        // constraint would exist, look correct, and constrain nothing. This was
        // not reasoned about in advance — a test asserted two workspaces could
        // not both register the same domain, and it failed.
        //
        // MySQL 8 has no partial indexes, so the fix is a generated column that
        // is 1 while the row is live and NULL once it is soft-deleted. Live rows
        // are constrained; deleted rows are exempt by the same NULL rule that
        // caused the problem. IF(deleted_at IS NULL, ...) is deterministic and
        // therefore legal in a generated column.
        //
        // A mail domain is globally unique, NOT unique per workspace: MX is a
        // property of the domain in public DNS, so two tenants cannot both
        // operate mail for it.
        DB::statement(
            'ALTER TABLE email_domains
                ADD COLUMN active_flag TINYINT UNSIGNED
                    GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED'
        );

        DB::statement('ALTER TABLE email_domains ADD UNIQUE KEY email_dom_domain_uq (domain, active_flag)');
    }

    public function down(): void
    {
        Schema::dropIfExists('email_domains');
    }
};
