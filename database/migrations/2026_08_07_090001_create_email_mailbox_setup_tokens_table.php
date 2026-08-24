<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 · E7.3 — LevelUp's own mailbox setup tokens.
 *
 * These exist so that the PROVIDER never contacts a customer. The customer
 * follows a LevelUp link, on a LevelUp page, and chooses their own password.
 *
 * THE RAW TOKEN IS NEVER STORED. Only a SHA-256 hash, so a database disclosure
 * cannot be replayed into an account takeover. The password is not stored in
 * any form whatsoever — not hashed, not encrypted, not truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_mailbox_setup_tokens', function (Blueprint $table) {
            $table->id();

            // Tenancy first: every lookup is scoped, and a token minted for one
            // workspace must be meaningless in another.
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('email_domain_id')->index();
            $table->unsignedBigInteger('email_mailbox_id')->index();

            // SHA-256 of the raw token. Unique so a collision cannot be
            // inserted, and indexed because it is the only lookup key.
            $table->char('token_hash', 64)->unique();

            // Purpose-bound: a setup token may never be accepted by a reset
            // flow, or the reverse.
            $table->string('purpose', 32)->default('mailbox_password_setup')->index();

            // Where the LevelUp email was sent. Needed to resend, and to show an
            // operator who was contacted. Never the customer's password.
            $table->string('recipient_email', 320);

            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('superseded_reason', 64)->nullable();

            $table->unsignedBigInteger('issued_by_user_id')->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();

            $table->timestamps();

            // The hot path: "is there a live token for this mailbox?"
            $table->index(['email_mailbox_id', 'consumed_at', 'superseded_at'], 'email_setup_live_idx');
        });

        // A mailbox may have at most ONE live token. Enforced in the database
        // rather than in code, because "issue a replacement" and "click the old
        // link" can race, and the loser of that race must be rejected by the
        // schema rather than by whichever request happened to run second.
        //
        // MySQL treats NULLs as distinct in a unique index, so a generated
        // column collapses "live" to a single value and every consumed or
        // superseded row drops out of the constraint entirely.
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE email_mailbox_setup_tokens
                ADD COLUMN live_flag TINYINT UNSIGNED
                    GENERATED ALWAYS AS (
                        IF(consumed_at IS NULL AND superseded_at IS NULL, 1, NULL)
                    ) STORED'
        );

        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE email_mailbox_setup_tokens
                ADD UNIQUE KEY email_setup_one_live_uq (email_mailbox_id, purpose, live_flag)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('email_mailbox_setup_tokens');
    }
};
