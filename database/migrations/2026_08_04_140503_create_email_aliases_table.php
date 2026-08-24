<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · E1 — BUSINESS EMAIL: ALIAS.
 *
 * An alias delivers mail addressed to one local part into a mailbox we operate,
 * or to a single named address. It stores no mail of its own, so removing one
 * is reversible in a way deleting a mailbox is not.
 *
 * THE TARGET IS EXCLUSIVE, AND THE DATABASE ENFORCES IT.
 * An alias points at a mailbox OR at an address, never both and never neither.
 * Two nullable columns and a convention would permit all four combinations, and
 * the two invalid ones — no target, and two conflicting targets — are exactly
 * the states that make routing undefined. `target_type` names the intent and a
 * CHECK constraint makes the other three combinations unrepresentable.
 *
 * MySQL enforces CHECK constraints from 8.0.16; this host runs 8.0.46, verified
 * before this migration was written. The constraint is therefore real, not
 * decorative, and the model-level validation exists in addition to it rather
 * than instead of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_aliases')) {
            return;
        }

        Schema::create('email_aliases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('email_domain_id');

            $table->string('source_local_part', 64);

            // mailbox | address
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_mailbox_id')->nullable();
            // RFC 5321 — a full address is at most 320 octets.
            $table->string('target_address', 320)->nullable();

            // EmailAliasState.
            $table->string('lifecycle_state', 32)->default('requested');
            $table->string('previous_state', 32)->nullable();

            $table->unsignedBigInteger('provider_connection_id')->nullable();
            $table->unsignedBigInteger('last_operation_id')->nullable();

            $table->timestamp('state_changed_at')->nullable();
            $table->unsignedBigInteger('state_changed_by_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // IDENTITY is enforced after creation — see the ALTER at the end of
            // up(), and the note on email_domains for why deleted_at cannot be
            // part of a unique key.

            $table->index(['workspace_id', 'lifecycle_state'], 'email_alias_ws_state_idx');
            $table->index(['email_domain_id', 'lifecycle_state'], 'email_alias_dom_state_idx');
            $table->index('target_mailbox_id', 'email_alias_target_idx');

            $table->foreign('email_domain_id', 'email_alias_domain_fk')
                ->references('id')->on('email_domains')
                ->cascadeOnDelete();

            // A mailbox that an alias delivers into may not be hard-deleted out
            // from under it. Cascading would silently destroy the alias; nulling
            // would leave it pointing nowhere while still claiming to route.
            $table->foreign('target_mailbox_id', 'email_alias_mailbox_fk')
                ->references('id')->on('email_mailboxes')
                ->restrictOnDelete();

            $table->foreign('provider_connection_id', 'email_alias_provconn_fk')
                ->references('id')->on('infra_provider_connections')
                ->restrictOnDelete();
        });

        // One LIVE source local part per domain — see email_domains for why the
        // generated flag is necessary.
        DB::statement(
            'ALTER TABLE email_aliases
                ADD COLUMN active_flag TINYINT UNSIGNED
                    GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED'
        );

        DB::statement(
            'ALTER TABLE email_aliases
                ADD UNIQUE KEY email_alias_identity_uq (email_domain_id, source_local_part, active_flag)'
        );

        // Exactly one target, matching the declared target_type. The three
        // invalid shapes (no target, both targets, target that contradicts the
        // declared type) become unrepresentable rather than merely discouraged.
        DB::statement(<<<'SQL'
            ALTER TABLE email_aliases
            ADD CONSTRAINT email_alias_target_chk CHECK (
                (target_type = 'mailbox' AND target_mailbox_id IS NOT NULL AND target_address IS NULL)
                OR
                (target_type = 'address' AND target_address IS NOT NULL AND target_mailbox_id IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_aliases');
    }
};
