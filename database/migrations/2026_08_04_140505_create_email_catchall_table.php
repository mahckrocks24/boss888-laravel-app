<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · E1 — BUSINESS EMAIL: CATCH-ALL.
 *
 * WHY THIS IS A TABLE RATHER THAN COLUMNS ON email_domains
 * A catch-all is one-per-domain, so folding it into the domain row is the
 * obvious move. It is the wrong one here for a specific reason: a catch-all has
 * its OWN provisioning lifecycle, its own governed operation, its own provider
 * outcome and its own failure mode. Inlined, every one of those would become a
 * nullable column on email_domains whose meaning depended on reading a second
 * column first — the precise pattern the schema rules forbid.
 *
 * As its own row the absence of a catch-all is representable as the absence of
 * a row, and its presence always carries a complete, self-describing state.
 *
 * NO `enabled` BOOLEAN. `state` is the single answer (EmailCatchAllState). A
 * boolean alongside a state is two sources of truth that drift; and `enabled`
 * cannot express "we asked the provider and are waiting", which is a real and
 * common condition.
 *
 * ONE PER DOMAIN is a unique constraint, not a service-layer promise.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_catchall')) {
            return;
        }

        Schema::create('email_catchall', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('email_domain_id');

            // EmailCatchAllState. 'disabled' is the safe initial state: a
            // catch-all accepts mail for every unassigned local part and is a
            // well-known spam magnet, so it is never on by default.
            $table->string('state', 24)->default('disabled');
            $table->string('previous_state', 24)->nullable();

            // mailbox | address. NULL only while disabled or failed — see the
            // CHECK constraint below, which makes that the ONLY case.
            $table->string('target_type', 16)->nullable();
            $table->unsignedBigInteger('target_mailbox_id')->nullable();
            $table->string('target_address', 320)->nullable();

            $table->unsignedBigInteger('provider_connection_id')->nullable();
            $table->unsignedBigInteger('last_operation_id')->nullable();

            $table->timestamp('state_changed_at')->nullable();
            $table->unsignedBigInteger('state_changed_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->timestamps();

            // One per domain, enforced structurally. No soft deletes: a
            // catch-all is switched off rather than removed, and the row dies
            // with its domain.
            $table->unique('email_domain_id', 'email_ca_domain_uq');

            $table->index(['workspace_id', 'state'], 'email_ca_ws_state_idx');
            $table->index('target_mailbox_id', 'email_ca_target_idx');

            $table->foreign('email_domain_id', 'email_ca_domain_fk')
                ->references('id')->on('email_domains')
                ->cascadeOnDelete();

            $table->foreign('target_mailbox_id', 'email_ca_mailbox_fk')
                ->references('id')->on('email_mailboxes')
                ->restrictOnDelete();

            $table->foreign('provider_connection_id', 'email_ca_provconn_fk')
                ->references('id')->on('infra_provider_connections')
                ->restrictOnDelete();
        });

        // A catch-all that is configuring, enabled, disabling or reconciling
        // MUST have exactly one target consistent with its declared type. Only
        // 'disabled' and 'configuration_failed' may have none — those are the
        // two states in which no delivery is being attempted.
        DB::statement(<<<'SQL'
            ALTER TABLE email_catchall
            ADD CONSTRAINT email_ca_target_chk CHECK (
                (
                    state IN ('disabled', 'configuration_failed')
                    AND target_type IS NULL
                    AND target_mailbox_id IS NULL
                    AND target_address IS NULL
                )
                OR
                (
                    state IN ('disabled', 'configuration_failed', 'configuring', 'enabled', 'disabling', 'reconciling')
                    AND (
                        (target_type = 'mailbox' AND target_mailbox_id IS NOT NULL AND target_address IS NULL)
                        OR
                        (target_type = 'address' AND target_address IS NOT NULL AND target_mailbox_id IS NULL)
                    )
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_catchall');
    }
};
