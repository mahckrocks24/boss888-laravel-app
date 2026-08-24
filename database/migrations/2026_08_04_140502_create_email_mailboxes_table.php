<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · E1 — BUSINESS EMAIL: MAILBOX.
 *
 * The billable, quota-consuming unit, and the only entity in this family whose
 * deletion destroys customer data that no rollback restores.
 *
 * NO STORED ADDRESS COLUMN. A mailbox is identified by (domain, local_part).
 * Storing the composed address as well would create two sources of truth for
 * the same fact and a drift path when a domain is corrected. The address is
 * derived on read; the unique constraint is on the pair that actually defines
 * identity.
 *
 * NO PASSWORD MATERIAL. Not a hash, not a hint, not a "temporary" column. The
 * provider owns authentication; this engine never sees, stores, logs or returns
 * a mailbox password. `password_set_at` and `last_password_reset_at` record
 * that an event happened, never what it contained.
 *
 * QUOTA HAS NO DEFAULT. A mailbox with an unstated quota is not a real mailbox,
 * and defaulting to 0 or to some assumed plan size would be a fabricated value
 * that later reads as deliberate. It must be supplied at creation.
 *
 * SUSPENSION IS TWO FACTS. `lifecycle_state` says mail is stopped;
 * `suspension_reason_code` says who may restart it — a customer-requested
 * suspension is customer-reversible, a billing hold is not. Collapsing them
 * would either multiply the state machine or lose the distinction entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_mailboxes')) {
            return;
        }

        Schema::create('email_mailboxes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('email_domain_id');

            // RFC 5321 section 4.5.3.1 — a local part is at most 64 octets.
            $table->string('local_part', 64);

            // Genuinely optional presentation data. Absence means "not set",
            // which is a complete and honest answer, not a hidden state.
            $table->string('display_name', 191)->nullable();

            // Explicit at creation. See the note above on why there is no default.
            $table->unsignedInteger('quota_mb');

            // EmailMailboxState.
            $table->string('lifecycle_state', 32)->default('requested');
            $table->string('previous_state', 32)->nullable();

            // none | customer_requested | admin_enforced | billing_hold | abuse_hold
            $table->string('suspension_reason_code', 32)->default('none');
            $table->timestamp('suspended_at')->nullable();
            $table->unsignedBigInteger('suspended_by_user_id')->nullable();
            $table->text('suspension_note')->nullable();

            // Events only. Never any credential material.
            $table->timestamp('password_set_at')->nullable();
            $table->timestamp('last_password_reset_at')->nullable();

            // Denormalised latest sample, for list screens and quota warnings.
            // NULL means never sampled, which is different from zero bytes used
            // and must stay different — a list that shows 0 MB for an unsampled
            // mailbox is a lie the customer cannot detect.
            $table->unsignedBigInteger('storage_used_mb')->nullable();
            $table->timestamp('usage_observed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            // Pinned at provisioning: a mailbox stays with the connection that
            // created it even if the domain is later re-bound.
            $table->unsignedBigInteger('provider_connection_id')->nullable();
            $table->unsignedBigInteger('last_operation_id')->nullable();

            $table->timestamp('state_changed_at')->nullable();
            $table->unsignedBigInteger('state_changed_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->json('settings_json')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // IDENTITY is enforced after creation — see the ALTER at the end of
            // up(), and the note on email_domains for why deleted_at cannot be
            // part of a unique key.

            $table->index(['workspace_id', 'lifecycle_state'], 'email_mbx_ws_state_idx');
            $table->index(['email_domain_id', 'lifecycle_state'], 'email_mbx_dom_state_idx');
            $table->index(['workspace_id', 'usage_observed_at'], 'email_mbx_ws_usage_idx');
            $table->index('suspension_reason_code', 'email_mbx_susp_idx');
            $table->index('provider_connection_id', 'email_mbx_provconn_idx');

            $table->foreign('email_domain_id', 'email_mbx_domain_fk')
                ->references('id')->on('email_domains')
                ->cascadeOnDelete();

            $table->foreign('provider_connection_id', 'email_mbx_provconn_fk')
                ->references('id')->on('infra_provider_connections')
                ->restrictOnDelete();
        });

        // One LIVE local part per domain. `active_flag` is 1 while the row is
        // live and NULL once soft-deleted, which is what makes the unique key
        // apply to live rows only — see the full explanation on email_domains.
        DB::statement(
            'ALTER TABLE email_mailboxes
                ADD COLUMN active_flag TINYINT UNSIGNED
                    GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED'
        );

        DB::statement(
            'ALTER TABLE email_mailboxes
                ADD UNIQUE KEY email_mbx_identity_uq (email_domain_id, local_part, active_flag)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('email_mailboxes');
    }
};
