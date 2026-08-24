<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · E1 — BUSINESS EMAIL: USAGE SAMPLES.
 *
 * WHY THIS IS NOT infra_usage_records, AND NOT infra_observation_facts
 * Both were evaluated for reuse before this table was written, and both are
 * genuinely reused elsewhere in this milestone. Neither fits here:
 *
 *   `infra_usage_records` is BILLING-PERIOD metering. Its identity is
 *   (workspace, subscription, metric, period_start) and it carries overage and
 *   invoicing state. It answers "what do we charge for July". It cannot hold
 *   many samples inside one period, which is exactly what quota enforcement
 *   needs. Business Email usage ROLLS UP INTO it; it is not a replacement.
 *
 *   `infra_observation_facts` is dimension-agnostic and would accept these rows
 *   structurally — but the measured quantities would land in `observed_json`.
 *   "Every mailbox above 90% of quota" is a first-class query path for both the
 *   customer portal and billing, and answering it by scanning JSON in an
 *   unindexed column is not a schema decision anyone would defend later.
 *
 * So this table is the numeric, indexable time series, and it is deliberately
 * narrow: it measures, it does not conclude. Health conclusions drawn from
 * these numbers belong in infra_observation_facts with the rest of the estate.
 *
 * ABSENCE IS NOT ZERO. Every measure is nullable because providers differ in
 * what they report, and a provider that does not expose send counts must
 * produce NULL rather than 0. A dashboard that shows "0 messages sent" for an
 * unreported metric is a fabricated number, which is worse than a blank.
 *
 * IDEMPOTENCY. A usage sync is a mutating operation in the governance sense —
 * it writes rows — so it carries an idempotency key unique per workspace,
 * mirroring infra_operations. A retried sync job cannot double-insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_usage')) {
            return;
        }

        Schema::create('email_usage', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('email_domain_id');

            // domain | mailbox. Explicit rather than inferred from whether
            // email_mailbox_id is null — see the CHECK constraint below.
            $table->string('scope', 16);
            $table->unsignedBigInteger('email_mailbox_id')->nullable();

            // Measures. NULL means "the provider did not report this", which is
            // a different fact from zero and must stay different.
            $table->unsignedBigInteger('storage_used_mb')->nullable();
            $table->unsignedBigInteger('storage_quota_mb')->nullable();
            $table->unsignedBigInteger('messages_sent')->nullable();
            $table->unsignedBigInteger('messages_received')->nullable();
            $table->unsignedInteger('mailbox_count')->nullable();

            // provider | internal | estimated — the same vocabulary
            // infra_usage_records already uses, so a rollup does not have to
            // translate between two ideas of where a number came from.
            $table->string('source', 24)->default('provider');

            // AssetLifecycle confidence ladder: unknown | low | observed |
            // verified | customer_confirmed. Reused rather than reinvented so
            // estate-wide confidence keeps one meaning.
            $table->string('confidence', 24)->default('unknown');

            // When the measurement was TAKEN, which is not when the row was
            // written. Billing and quota decisions must use the former.
            $table->timestamp('observed_at');

            $table->unsignedBigInteger('provider_connection_id')->nullable();
            $table->unsignedBigInteger('operation_id')->nullable();

            // Unique per workspace, mirroring infra_operations. A retried sync
            // reuses the key and cannot double-insert.
            $table->string('idempotency_key', 191);

            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key'], 'email_usg_idem_uq');

            $table->index(['email_mailbox_id', 'observed_at'], 'email_usg_mbx_time_idx');
            $table->index(['email_domain_id', 'observed_at'], 'email_usg_dom_time_idx');
            $table->index(['workspace_id', 'scope', 'observed_at'], 'email_usg_ws_scope_idx');
            $table->index(['workspace_id', 'storage_used_mb'], 'email_usg_ws_storage_idx');

            $table->foreign('email_domain_id', 'email_usg_domain_fk')
                ->references('id')->on('email_domains')
                ->cascadeOnDelete();

            // Samples describe a mailbox and mean nothing without it. Unlike
            // aliases, there is no routing to protect, so cascading is correct.
            // Billing history survives independently in infra_usage_records.
            $table->foreign('email_mailbox_id', 'email_usg_mailbox_fk')
                ->references('id')->on('email_mailboxes')
                ->cascadeOnDelete();

            $table->foreign('provider_connection_id', 'email_usg_provconn_fk')
                ->references('id')->on('infra_provider_connections')
                ->restrictOnDelete();
        });

        // Scope and mailbox linkage must agree. A "mailbox" sample with no
        // mailbox, or a "domain" sample attached to one, is a measurement whose
        // subject is undefined.
        DB::statement(<<<'SQL'
            ALTER TABLE email_usage
            ADD CONSTRAINT email_usg_scope_chk CHECK (
                (scope = 'mailbox' AND email_mailbox_id IS NOT NULL)
                OR
                (scope = 'domain' AND email_mailbox_id IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_usage');
    }
};
