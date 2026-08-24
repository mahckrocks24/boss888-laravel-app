<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · E1 — BUSINESS EMAIL: FORWARDER.
 *
 * A forwarder relays mail to an address outside the domain, usually outside our
 * control entirely. That external destination is what separates it from an
 * alias and is why it carries loop safety that an alias does not need.
 *
 * LOOP SAFETY IS A COLUMN, NOT A LIFECYCLE STATE.
 * A forwarder can be `active` and dangerous simultaneously, and can BECOME
 * dangerous long after creation when someone else adds the second half of the
 * loop. Folding it into lifecycle_state would force a single column to answer
 * two independent questions — "does this rule exist?" and "is it safe?" — and
 * one of the answers would have to be wrong.
 *
 * The default is `unchecked`, which ForwarderLoopSafety classifies as BLOCKING.
 * A forwarder that has never been evaluated may not be provisioned. Defaulting
 * to `safe` would be a fabricated clearance, and the failure mode of that
 * mistake is mail amplification against a third party who never agreed to
 * anything.
 *
 * `safe` means "no loop is visible in records we hold" and is named for what it
 * can actually prove. The far side may forward back to us and we cannot see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_forwarders')) {
            return;
        }

        Schema::create('email_forwarders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('email_domain_id');

            $table->string('source_local_part', 64);

            // Always external and always required — a forwarder with no
            // destination is not a forwarder.
            $table->string('destination_address', 320);

            // EmailForwarderState.
            $table->string('lifecycle_state', 32)->default('requested');
            $table->string('previous_state', 32)->nullable();

            // ForwarderLoopSafety. 'unchecked' blocks provisioning.
            $table->string('loop_check_state', 24)->default('unchecked');
            $table->timestamp('loop_checked_at')->nullable();
            $table->text('loop_check_reason')->nullable();
            $table->unsignedSmallInteger('loop_check_hops')->nullable();

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

            $table->index(['workspace_id', 'lifecycle_state'], 'email_fwd_ws_state_idx');
            $table->index(['email_domain_id', 'lifecycle_state'], 'email_fwd_dom_state_idx');
            $table->index(['email_domain_id', 'source_local_part'], 'email_fwd_source_idx');
            $table->index('loop_check_state', 'email_fwd_loop_idx');

            $table->foreign('email_domain_id', 'email_fwd_domain_fk')
                ->references('id')->on('email_domains')
                ->cascadeOnDelete();

            $table->foreign('provider_connection_id', 'email_fwd_provconn_fk')
                ->references('id')->on('infra_provider_connections')
                ->restrictOnDelete();
        });

        // A source may legitimately fan out to several destinations, so
        // identity is the (source, destination) pair rather than the source
        // alone — and it constrains LIVE rows only. See email_domains for why
        // the generated flag is necessary.
        //
        // Key width: 8 + 64*4 + 320*4 + 1 = 1,553 bytes, inside MySQL's
        // 3,072-byte limit. That limit has already broken a clean install on
        // this project once, so it is worth stating rather than assuming.
        DB::statement(
            'ALTER TABLE email_forwarders
                ADD COLUMN active_flag TINYINT UNSIGNED
                    GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED'
        );

        DB::statement(
            'ALTER TABLE email_forwarders
                ADD UNIQUE KEY email_fwd_identity_uq
                    (email_domain_id, source_local_part, destination_address, active_flag)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('email_forwarders');
    }
};
